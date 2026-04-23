<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\ProductDescription;
use App\Models\PromptCategory;
use App\Models\PromptCategoryDescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromptCategoryController extends Controller
{
    use NormalizesLocalizedSlugs;

    public function index()
    {
        $pageTitle = 'Категории промтов';
        $defaultLanguage = Language::getDefault();
        $aiFieldOptions = ProductDescription::aiFieldLabels();
        $categories = PromptCategory::with(['parent.descriptions', 'descriptions', 'manufacturer'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(15);
        $currentPageItems = $categories->getCollection();
        $selectedRawCategory = $currentPageItems->first();

        return view('admin.prompt_categories.index', compact('categories', 'pageTitle', 'defaultLanguage', 'aiFieldOptions', 'selectedRawCategory'));
    }

    public function create()
    {
        $pageTitle = 'Категории промтов - Создание';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $parentOptions = PromptCategory::treeForParentSelect($defaultLanguage, []);
        $manufacturers = Manufacturer::query()->orderBy('sort_order')->orderBy('name')->get();
        $aiFieldOptions = ProductDescription::aiFieldLabels();

        return view('admin.prompt_categories.create', compact('pageTitle', 'languages', 'defaultLanguage', 'parentOptions', 'manufacturers', 'aiFieldOptions'));
    }

    public function store(Request $request)
    {
        $request->merge([
            'parent_id' => $request->filled('parent_id') ? (int) $request->parent_id : null,
        ]);

        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык.');
        }

        $rules = [
            'parent_id' => ['nullable', 'integer', 'exists:prompt_categories,id'],
            'manufacturer_id' => ['nullable', 'exists:manufacturers,id'],
            'ai_field' => ['nullable', Rule::in(ProductDescription::aiFieldKeys())],
            'row_data' => ['nullable', 'string'],
            'stage_1_extraction' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['slug_'.$suffix] = [
                $language->is_default ? 'required' : 'nullable',
                'string',
                'max:255',
                Rule::unique('prompt_category_descriptions', 'slug')->where('language_id', $language->id),
            ];
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['stage_2_live_'.$suffix] = 'nullable|string';
            $rules['stage_3_edit_'.$suffix] = 'nullable|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages) {
            $category = PromptCategory::create([
                'parent_id' => $request->input('parent_id'),
                'manufacturer_id' => $request->input('manufacturer_id'),
                'ai_field' => $request->input('ai_field'),
                'row_data' => $request->input('row_data'),
                'stage_1_extraction' => $request->input('stage_1_extraction'),
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

                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    continue;
                }

                $slug = (string) $slugInput;
                if ($slug === '') {
                    continue;
                }

                PromptCategoryDescription::create([
                    'prompt_category_id' => $category->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'stage_2_live' => $request->input('stage_2_live_'.$suffix),
                    'stage_3_edit' => $request->input('stage_3_edit_'.$suffix),
                ]);
            }

            PromptCategory::rebuildPaths();
        });

        return redirect()->route('admin.prompt-categories.index')->with('success', 'Категория промтов создана');
    }

    public function edit(string $id)
    {
        $pageTitle = 'Категории промтов - Редактирование';
        $category = PromptCategory::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $excludeIds = array_merge([(int) $category->id], $category->descendantIdList());
        $parentOptions = PromptCategory::treeForParentSelect($defaultLanguage, $excludeIds);
        $manufacturers = Manufacturer::query()->orderBy('sort_order')->orderBy('name')->get();
        $aiFieldOptions = ProductDescription::aiFieldLabels();

        return view('admin.prompt_categories.edit', compact('pageTitle', 'category', 'languages', 'defaultLanguage', 'parentOptions', 'manufacturers', 'aiFieldOptions'));
    }

    public function update(Request $request, string $id)
    {
        $request->merge([
            'parent_id' => $request->filled('parent_id') ? (int) $request->parent_id : null,
        ]);

        $category = PromptCategory::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык.');
        }

        $rules = [
            'parent_id' => [
                'nullable',
                'integer',
                'exists:prompt_categories,id',
                Rule::notIn(array_merge([(int) $category->id], $category->descendantIdList())),
            ],
            'manufacturer_id' => ['nullable', 'exists:manufacturers,id'],
            'ai_field' => ['nullable', Rule::in(ProductDescription::aiFieldKeys())],
            'row_data' => ['nullable', 'string'],
            'stage_1_extraction' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $description = $category->descriptions->firstWhere('language_id', $language->id);
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['slug_'.$suffix] = [
                $language->is_default ? 'required' : 'nullable',
                'string',
                'max:255',
                Rule::unique('prompt_category_descriptions', 'slug')
                    ->where('language_id', $language->id)
                    ->ignore($description?->id),
            ];
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['stage_2_live_'.$suffix] = 'nullable|string';
            $rules['stage_3_edit_'.$suffix] = 'nullable|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $category) {
            $category->update([
                'parent_id' => $request->input('parent_id'),
                'manufacturer_id' => $request->input('manufacturer_id'),
                'ai_field' => $request->input('ai_field'),
                'row_data' => $request->input('row_data'),
                'stage_1_extraction' => $request->input('stage_1_extraction'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);

                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    PromptCategoryDescription::query()
                        ->where('prompt_category_id', $category->id)
                        ->where('language_id', $language->id)
                        ->delete();
                    continue;
                }

                $slug = (string) $slugInput;
                if ($slug === '') {
                    continue;
                }

                PromptCategoryDescription::updateOrCreate(
                    [
                        'prompt_category_id' => $category->id,
                        'language_id' => $language->id,
                    ],
                    [
                        'name' => $name ?: $slug,
                        'slug' => $slug,
                        'description' => $request->input('description_'.$suffix),
                        'stage_2_live' => $request->input('stage_2_live_'.$suffix),
                        'stage_3_edit' => $request->input('stage_3_edit_'.$suffix),
                    ]
                );
            }

            PromptCategory::rebuildPaths();
        });

        return redirect()
            ->route('admin.prompt-categories.edit', $category->id)
            ->with('success', 'Категория промтов обновлена');
    }

    public function destroy(string $id)
    {
        PromptCategory::findOrFail($id)->delete();
        PromptCategory::rebuildPaths();

        return redirect()->route('admin.prompt-categories.index')->with('success', 'Категория промтов удалена');
    }

    public function updateRawData(Request $request, string $id)
    {
        $data = $request->validate([
            'row_data' => ['nullable', 'string'],
        ]);

        $category = PromptCategory::findOrFail($id);
        $category->update([
            'row_data' => $data['row_data'] ?? null,
        ]);

        return redirect()->route('admin.prompt-categories.index')->with('success', 'Нотация к сырью обновлена');
    }
}
