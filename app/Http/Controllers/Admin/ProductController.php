<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Jobs\TranslateProductJob;
use App\Models\Category;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Models\User;
use App\Support\AiDescriptionJsonNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    // Трейт с логикой нормализации slug'ов для локализованных полей (общий для разных форм).
    use NormalizesLocalizedSlugs;


    /**
     * Запускает AI‑генерацию контента для товара в фоновом режиме.
     *
     * Этот метод вызывается AJAX‑скриптом из шаблона edit.blade.php
     * (кнопка "Сгенерировать контент для всех языков").
     *
     * Логика:
     * - валидируем входящие данные;
     * - находим товар;
     * - отправляем job TranslateProductJob в очередь (Redis);
     * - возвращаем короткий JSON‑ответ для фронта.
     */
    public function generateAi(Request $request)
    {
        $aiFields = array_keys($this->getAiPrefixedDescriptionFields());

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'source_text' => ['nullable', 'string'],
            'result_text' => ['nullable', 'string'],
            'ai_field' => ['required', 'string', Rule::in($aiFields)],
        ]);

        $product = Product::findOrFail($data['product_id']);
        //SELECT * FROM products WHERE id = $request LIMIT 1.

        $baseText = trim((string) ($data['result_text'] ?? ''));
        if ($baseText === '') {
            $baseText = trim((string) ($product->result ?? ''));
        }
        if ($baseText === '') {
            $baseText = trim((string) ($data['source_text'] ?? ''));
        }
        if ($baseText === '' || mb_strlen($baseText) < 10) {
            return response()->json([
                'message' => 'Добавьте текст в Result (или Source Raw Materials) минимум 10 символов.',
            ], 422);
        }

        TranslateProductJob::dispatch($product, $baseText, $data['ai_field']);

        return response()->json([
            'message' => 'Генерация запущена в фоне',
        ]);
    }

    public function index(Request $request)
    {
        $pageTitle = 'Товары';
        $defaultLanguage = Language::getDefault();
        $productsQuery = Product::query()
            ->with([
                'descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id),
                'manufacturer',
                'author',
                'categories.descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id),
            ])
            ->orderByDesc('id');

        $selectedAuthor = $request->query('author');
        if ($selectedAuthor) {
            $productsQuery->where('author_id', (int) $selectedAuthor);
        }

        $selectedCategory = $request->query('category');
        if ($selectedCategory) {
            $productsQuery->whereHas('categories', function ($q) use ($selectedCategory) {
                $q->where('categories.id', (int) $selectedCategory);
            });
        }

        $selectedManufacturer = $request->query('manufacturer');
        if ($selectedManufacturer) {
            $productsQuery->where('manufacturer_id', (int) $selectedManufacturer);
        }

        $selectedMonth = $request->query('month');
        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth) === 1) {
            [$year, $month] = explode('-', $selectedMonth);
            $productsQuery
                ->whereYear('created_at', (int) $year)
                ->whereMonth('created_at', (int) $month);
        }

        $products = $productsQuery->paginate(20)->withQueryString();

        $authors = User::query()
            ->where(function ($q) {
                $q->whereNotNull('role_id')
                    ->orWhereNotNull('role');
            })
            ->whereIn('id', Product::query()->whereNotNull('author_id')->pluck('author_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $categories = Category::query()
            ->where('status', true)
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $defaultLanguage?->id)])
            ->orderBy('sort_order')
            ->get();

        $manufacturers = Manufacturer::query()
            ->whereIn('id', Product::query()->whereNotNull('manufacturer_id')->pluck('manufacturer_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $months = Product::query()
            ->whereNotNull('created_at')
            ->orderByDesc('created_at')
            ->get(['created_at'])
            ->map(fn ($p) => optional($p->created_at)->format('Y-m'))
            ->filter()
            ->unique()
            ->values();

        return view('admin.products.index', compact(
            'products',
            'pageTitle',
            'defaultLanguage',
            'authors',
            'categories',
            'manufacturers',
            'months',
            'selectedAuthor',
            'selectedCategory',
            'selectedManufacturer',
            'selectedMonth'
        ));
    }

    public function create()
    {
        $pageTitle = 'Товар — создание';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::orderBy('sort_order')->orderBy('name')->get();
        $categories = Category::with('descriptions')->orderBy('sort_order')->get();
        $aiFields = $this->getAiPrefixedDescriptionFields();

        return view('admin.products.create', compact('pageTitle', 'languages', 'defaultLanguage', 'manufacturers', 'categories', 'aiFields'));
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        $rules = [
            'model' => 'required|string|max:64',
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'status' => 'nullable|boolean',
            'source_text' => 'nullable|string',
            'result' => 'nullable|string',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'exists:categories,id',
        ];
        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            if ($language->is_default) {
                $rules['slug_'.$suffix] = [
                    'required', 'string', 'max:255',
                    Rule::unique('product_descriptions', 'slug')->where('language_id', $language->id),
                ];
            } else {
                $rules['slug_'.$suffix] = [
                    'nullable', 'string', 'max:255',
                    Rule::unique('product_descriptions', 'slug')->where('language_id', $language->id),
                ];
            }
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['ai_text_about_the_country_'.$suffix] = 'nullable|string';
            $rules['ai_reviews_from_tourists_'.$suffix] = 'nullable|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        DB::transaction(function () use ($request, $languages) {
            $authorId = null;
            $authUser = Auth::user();
            if ($authUser && ($authUser->role_id || !empty($authUser->role))) {
                $authorId = $authUser->id;
            }

            $product = Product::create([
                'model' => $request->model,
                'sku' => $request->input('sku'),
                // Поле image оставлено в БД, но скрыто из админ-формы.
                'image' => null,
                'manufacturer_id' => $request->input('manufacturer_id'),
                'author_id' => $authorId,
                'source_text' => $request->input('source_text'),
                'result' => $request->input('result'),
                'status' => $request->boolean('status'),
            ]);

            $product->categories()->sync($request->category_ids);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    continue;
                }
                $slug = (string) $slugInput;
                if (! $slug) {
                    continue;
                }
                ProductDescription::create([
                    'product_id' => $product->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'ai_text_about_the_country' => AiDescriptionJsonNormalizer::normalize($request->input('ai_text_about_the_country_'.$suffix)),
                    'ai_reviews_from_tourists' => AiDescriptionJsonNormalizer::normalize($request->input('ai_reviews_from_tourists_'.$suffix)),
                    'tag' => $request->input('tag_'.$suffix),
                    'meta_title' => $request->input('meta_title_'.$suffix),
                    'meta_description' => $request->input('meta_description_'.$suffix),
                    'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                ]);
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Товар создан');
    }

    public function edit(string $id)
    {
        $pageTitle = 'Товар — редактирование';
        $product = Product::with(['descriptions', 'categories'])->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::orderBy('sort_order')->orderBy('name')->get();
        $categories = Category::with('descriptions')->orderBy('sort_order')->get();
        $aiFields = $this->getAiPrefixedDescriptionFields();

        return view('admin.products.edit', compact('product', 'pageTitle', 'languages', 'defaultLanguage', 'manufacturers', 'categories', 'aiFields'));
    }

    public function update(Request $request, string $id)
    {
        $product = Product::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        $rules = [
            'model' => 'required|string|max:64',
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'status' => 'nullable|boolean',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'exists:categories,id',
        ];
        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $desc = $product->descriptions->firstWhere('language_id', $language->id);
            if ($language->is_default) {
                $rules['slug_'.$suffix] = [
                    'required', 'string', 'max:255',
                    Rule::unique('product_descriptions', 'slug')->where('language_id', $language->id)->ignore($desc?->id),
                ];
            } else {
                $rules['slug_'.$suffix] = [
                    'nullable', 'string', 'max:255',
                    Rule::unique('product_descriptions', 'slug')->where('language_id', $language->id)->ignore($desc?->id),
                ];
            }
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['ai_text_about_the_country_'.$suffix] = 'nullable|string';
            $rules['ai_reviews_from_tourists_'.$suffix] = 'nullable|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $product) {
            $authorId = $product->author_id;
            $authUser = Auth::user();
            if ($authUser && ($authUser->role_id || !empty($authUser->role))) {
                $authorId = $authUser->id;
            }

            $product->update([
                'model' => $request->model,
                'sku' => $request->input('sku'),
                'manufacturer_id' => $request->input('manufacturer_id'),
                'author_id' => $authorId,
                'source_text' => $request->input('source_text'),
                'result' => $request->input('result'),
                'status' => $request->boolean('status'),
            ]);
            $product->categories()->sync($request->category_ids);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    ProductDescription::query()
                        ->where('product_id', $product->id)
                        ->where('language_id', $language->id)
                        ->delete();
                    continue;
                }
                $slug = (string) $slugInput;
                if (! $slug) {
                    continue;
                }
                ProductDescription::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'language_id' => $language->id,
                    ],
                    [
                        'name' => $name ?: $slug,
                        'slug' => $slug,
                        'description' => $request->input('description_'.$suffix),
                        'ai_text_about_the_country' => AiDescriptionJsonNormalizer::normalize($request->input('ai_text_about_the_country_'.$suffix)),
                        'ai_reviews_from_tourists' => AiDescriptionJsonNormalizer::normalize($request->input('ai_reviews_from_tourists_'.$suffix)),
                        'tag' => $request->input('tag_'.$suffix),
                        'meta_title' => $request->input('meta_title_'.$suffix),
                        'meta_description' => $request->input('meta_description_'.$suffix),
                        'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                    ]
                );
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Товар обновлён');
    }

    public function destroy(string $id)
    {
        Product::findOrFail($id)->delete();

        return redirect()->route('admin.products.index')->with('success', 'Товар удалён');
    }

    /**
     * Возвращает только AI-поля из product_descriptions (с префиксом ai_).
     */
    private function getAiPrefixedDescriptionFields(): array
    {
        if (! Schema::hasTable('product_descriptions')) {
            return [];
        }

        $labels = [
            'ai_text_about_the_country' => 'Текст о стране',
            'ai_reviews_from_tourists' => 'Отзывы туристов',
        ];

        return collect(Schema::getColumnListing('product_descriptions'))
            ->filter(fn (string $column) => str_starts_with($column, 'ai_'))
            ->mapWithKeys(function (string $column) use ($labels) {
                $defaultLabel = ucfirst(str_replace('_', ' ', preg_replace('/^ai_/', '', $column)));

                return [$column => $labels[$column] ?? $defaultLabel];
            })
            ->all();
    }
}
