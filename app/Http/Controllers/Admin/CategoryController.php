<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Админка: CRUD категорий каталога.
 *
 * Модель данных:
 * - categories — «скелет»: parent_id, sort_order, status, дерево (rebuildPaths после изменений).
 * - category_descriptions — по одной строке на пару (category_id, language_id): name, slug, SEO-поля.
 *
 * Слаги уникальны в рамках language_id (не глобально по сайту). Для дефолтного языка name и slug обязательны;
 * для остальных локалей пустая пара name+slug означает «не создавать / при update — удалить описание».
 *
 * OpenAiService / TranslateProductJob здесь не используются — это отдельный контур продуктов.
 */
class CategoryController extends Controller
{
    use NormalizesLocalizedSlugs;

    /** Список с пагинацией; подгружаются parent и descriptions для отображения имён по языкам. */
    public function index()
    {
        $pageTitle = 'Категории - Список';
        $defaultLanguage = Language::getDefault();
        $categories = Category::with(['parent.descriptions', 'descriptions'])
            ->orderBy('sort_order')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return view('admin.categories.index', compact('categories', 'pageTitle', 'defaultLanguage'));
    }

    /** Форма создания: дерево родителей на языке по умолчанию + все языки из Language::forAdminForms(). */
    public function create()
    {
        $pageTitle = 'Категория - Создание';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $parentOptions = Category::treeForParentSelect($defaultLanguage, []);

        return view('admin.categories.create', compact('pageTitle', 'parentOptions', 'languages', 'defaultLanguage'));
    }

    /**
     * Создание: одна транзакция — Category, затем набор CategoryDescription по заполненным локалям.
     * mergeLocalizedSlugsFromRequest: автодополнение slug из name там, где задумано трейтом.
     */
    public function store(Request $request)
    {
        $request->merge([
            'parent_id' => $request->filled('parent_id') ? (int) $request->parent_id : null,
        ]);

        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        $rules = [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            if ($language->is_default) {
                $rules['slug_'.$suffix] = [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('category_descriptions', 'slug')->where('language_id', $language->id),
                ];
            } else {
                $rules['slug_'.$suffix] = [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('category_descriptions', 'slug')->where('language_id', $language->id),
                ];
            }
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['meta_title_'.$suffix] = 'nullable|string|max:255';
            $rules['meta_description_'.$suffix] = 'nullable|string|max:255';
            $rules['meta_keyword_'.$suffix] = 'nullable|string|max:255';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        // image/top/column захардкожены — расширение под медиа/витрину делается отдельно.
        DB::transaction(function () use ($request, $languages) {
            $category = Category::create([
                'parent_id' => $request->input('parent_id'),
                'image' => null,
                'top' => false,
                'column' => 0,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                // Необязательные локали: полностью пустые пропускаем (нет строки в category_descriptions).
                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    continue;
                }
                $slug = (string) $slugInput;
                if (! $slug) {
                    continue;
                }

                CategoryDescription::create([
                    'category_id' => $category->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'meta_title' => $request->input('meta_title_'.$suffix),
                    'meta_description' => $request->input('meta_description_'.$suffix),
                    'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                ]);
            }

            // Пересчёт materialized path / порядка в дереве после вставки.
            Category::rebuildPaths();
        });

        return redirect()->route('admin.categories.index')
            ->with('success', 'Категория успешно создана');
    }

    /**
     * Редактирование: текущая категория и её потомки исключаются из списка parent_id (исключить циклы в дереве).
     */
    public function edit(string $id)
    {
        $pageTitle = 'Категория - Редактирование';
        $category = Category::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $excludeIds = array_merge([(int) $category->id], $category->descendantIdList());
        $parentOptions = Category::treeForParentSelect($defaultLanguage, $excludeIds);

        return view('admin.categories.edit', compact('category', 'pageTitle', 'parentOptions', 'languages', 'defaultLanguage'));
    }

    /**
     * Обновление: валидация parent_id не допускает выбор себя или любого потомка как родителя.
     * Пустая необязательная локаль при update удаляет соответствующую CategoryDescription.
     */
    public function update(Request $request, string $id)
    {
        $request->merge([
            'parent_id' => $request->filled('parent_id') ? (int) $request->parent_id : null,
        ]);

        $category = Category::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        $rules = [
            'parent_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                Rule::notIn(array_merge([(int) $category->id], $category->descendantIdList())),
            ],
            'sort_order' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $desc = $category->descriptions->firstWhere('language_id', $language->id);
            if ($language->is_default) {
                $rules['slug_'.$suffix] = [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('category_descriptions', 'slug')
                        ->where('language_id', $language->id)
                        ->ignore($desc?->id),
                ];
            } else {
                $rules['slug_'.$suffix] = [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('category_descriptions', 'slug')
                        ->where('language_id', $language->id)
                        ->ignore($desc?->id),
                ];
            }
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['meta_title_'.$suffix] = 'nullable|string|max:255';
            $rules['meta_description_'.$suffix] = 'nullable|string|max:255';
            $rules['meta_keyword_'.$suffix] = 'nullable|string|max:255';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $category) {
            $category->update([
                'parent_id' => $request->input('parent_id'),
                'top' => false,
                'column' => 0,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                // Явное «очищение» опциональной локали: удаляем запись, чтобы не хранить пустые переводы.
                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    CategoryDescription::query()
                        ->where('category_id', $category->id)
                        ->where('language_id', $language->id)
                        ->delete();

                    continue;
                }
                $slug = (string) $slugInput;
                if (! $slug) {
                    continue;
                }

                CategoryDescription::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'language_id' => $language->id,
                    ],
                    [
                        'name' => $name ?: $slug,
                        'slug' => $slug,
                        'description' => $request->input('description_'.$suffix),
                        'meta_title' => $request->input('meta_title_'.$suffix),
                        'meta_description' => $request->input('meta_description_'.$suffix),
                        'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                    ]
                );
            }

            Category::rebuildPaths();
        });

        return redirect()->route('admin.categories.index')
            ->with('success', 'Категория успешно обновлена');
    }

    /** Удаление модели Category (каскад на descriptions настраивается в миграциях/модели). */
    public function destroy(string $id)
    {
        $category = Category::findOrFail($id);
        $category->delete();
        Category::rebuildPaths();

        return redirect()->route('admin.categories.index')
            ->with('success', 'Категория успешно удалена');
    }
}
