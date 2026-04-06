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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    // Трейт с логикой нормализации slug'ов для локализованных полей (общий для разных форм).
    use NormalizesLocalizedSlugs;


    /**
     * POST admin/products/generate-ai: ставит в очередь TranslateProductJob (OpenAI в воркере).
     * Подробности по шагам — в комментариях к строкам тела метода.
     */
    public function generateAi(Request $request)
    {
        // Белый список имён колонок: только те, что в БД начинаются с префикса ai_ (см. getAiPrefixedDescriptionFields).
        // array_keys: для Rule::in нужен плоский список строк, без человекочитаемых подписей-значений.
        $aiFields = array_keys($this->getAiPrefixedDescriptionFields());

        // При несоответствии правилам Laravel выбросит ValidationException → ответ 422 с телом ошибок валидации.
        $data = $request->validate([
            // ID товара, существующий в таблице products; фронт передаёт из формы редактирования.
            'product_id' => ['required', 'integer', 'exists:products,id'],
            // «Source Raw Materials» / сырой ввод с формы; может быть пустым, если текст уже в result_text или в БД.
            'source_text' => ['nullable', 'string'],
            // Текущее содержимое textarea Result на момент клика (может отличаться от сохранённого в products.result).
            'result_text' => ['nullable', 'string'],
            // Куда писать результат job: имя колонки product_descriptions, строго одно из $aiFields.
            'ai_field' => ['required', 'string', Rule::in($aiFields)],
        ]);

        // Один запрос SELECT по primary key; 404, если строка удалена между валидацией exists и этим запросом (редкий гон).
       
       // получаем все колонки по айди
        $product = Product::findOrFail($data['product_id']);

        // Цепочка приоритетов для входа в OpenAiService: сначала то, что пользователь видит в форме (result_text).
        $baseText = trim((string) ($data['result_text'] ?? ''));
        if ($baseText === '') {
            // Второй приоритет: последнее сохранённое поле products.result (если textarea не трогали или пустая).
            $baseText = trim((string) ($product->result ?? ''));
        }
        if ($baseText === '') {
            // Третий приоритет: отдельное поле «исходник» с формы (source_text / Source Raw Materials).
            $baseText = trim((string) ($data['source_text'] ?? ''));
        }
        // Минимум 10 символов — защита от случайного клика и пустого промпта для дорогого вызова API.
        if ($baseText === '' || mb_strlen($baseText) < 10) {
            // 422 Unprocessable Entity: фронт в AJAX обычно показывает data.message пользователю.
            return response()->json([
                'message' => 'Добавьте текст в Result (или Source Raw Materials) минимум 10 символов.',
            ], 422);
        }

        // Перед новой генерацией очищаем целевое поле у всех языков — иначе опрос checkAiStatus
        // видит старый JSON и отдаёт is_ready, хотя свежий job уже упал в логе.
        $targetField = $data['ai_field'];
        Cache::forget($this->aiGenerationErrorCacheKey($product->id, $targetField));
        $product->descriptions()->update([$targetField => null]);
        Cache::put($this->aiGenerationStartedCacheKey($product->id, $targetField), time(), 86400);

        dispatch(new TranslateProductJob($product, $baseText, $targetField));

        // 200 OK: job только поставлен, переводов в БД ещё нет — страницу нужно обновить после работы воркера.
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
        $pageTitle = 'Редактирование';
        // Загружаем товар со связями, чтобы не было N+1 запросов
        $product = Product::with(['descriptions', 'categories'])->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::orderBy('sort_order')->orderBy('name')->get();
        $categories = Category::with('descriptions')->orderBy('sort_order')->get();
        
        // Получаем список полей с префиксом ai_ (те самые ключи для семафора)
        $aiFields = $this->getAiPrefixedDescriptionFields();

        return view('admin.products.edit', compact('product', 'pageTitle', 'languages', 'defaultLanguage', 'manufacturers', 'categories', 'aiFields'));
    }

    public function update(Request $request, string $id)
    {
        $product = Product::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        
        // Проверка на наличие языков, чтобы не посыпались ошибки в циклах ниже
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык — после этого в формах появятся поля названий.');
        }

        // Базовые правила валидации для основных полей товара
        $rules = [
            'model' => 'required|string|max:64',
            'manufacturer_id' => 'nullable|exists:manufacturers,id',
            'status' => 'nullable|boolean',
            'category_ids' => 'required|array|min:1',
            'category_ids.*' => 'exists:categories,id',
        ];

        // Динамическая валидация для каждого языка
        foreach ($languages as $language) {
            $suffix = $language->code;
            
            // Название обязательно только для дефолтного языка
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            
            $desc = $product->descriptions->firstWhere('language_id', $language->id);
            
            // Уникальность Slug с учетом текущего ID описания (игнорируем текущую запись при проверке)
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
            
            // Правила для описаний и наших AI-полей
            $rules['description_'.$suffix] = 'nullable|string';
            $rules['ai_text_about_the_country_'.$suffix] = 'nullable|string';
            $rules['ai_reviews_from_tourists_'.$suffix] = 'nullable|string';
        }

        // Обработка автоматических слагов перед валидацией
        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        // Все изменения в БД оборачиваем в транзакцию — либо всё сохранится, либо ничего
        DB::transaction(function () use ($request, $languages, $product) {
            $authorId = $product->author_id;
            $authUser = Auth::user();
            
            // Если роль заполнена, обновляем автора на того, кто редактирует
            if ($authUser && ($authUser->role_id || !empty($authUser->role))) {
                $authorId = $authUser->id;
            }

            // Обновление основной таблицы товара
            $product->update([
                'model' => $request->model,
                'sku' => $request->input('sku'),
                'manufacturer_id' => $request->input('manufacturer_id'),
                'author_id' => $authorId,
                'source_text' => $request->input('source_text'), // Исходное сырье для ИИ
                'result' => $request->input('result'),           // Поле Result (Инкубатор)
                'status' => $request->boolean('status'),
            ]);
            
            // Синхронизация категорий (многие-ко-многим)
            $product->categories()->sync($request->category_ids);

            // Сохранение мультиязычных описаний
            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);
                
                // Если язык не дефолтный и поля пустые — удаляем описание для этого языка
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
                
                // Основная магия записи AI-полей с нормализацией JSON
                ProductDescription::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'language_id' => $language->id,
                    ],
                    [
                        'name' => $name ?: $slug,
                        'slug' => $slug,
                        'description' => $request->input('description_'.$suffix),
                        // Прогоняем через твой нормализатор, чтобы в БД лежал чистый JSON
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
     * Собирает список колонок таблицы product_descriptions, имена которых начинаются с ai_.
     *
     * Формат возврата: [ 'ai_some_field' => 'Подпись для админки', ... ].
     * Используется в create/edit (селект поля для генерации) и в generateAi (Rule::in по array_keys).
     *
     * Источник правды — схема БД: новая миграция с колонкой ai_* попадёт сюда без правок PHP,
     * кроме случая, когда нужна особая русская подпись — тогда добавляют ключ в $labels.
     */
    private function getAiPrefixedDescriptionFields(): array
    {
        // Твой "Золотой стандарт". Только те поля, которые реально работают.
        return [
            'ai_text_about_the_country' => 'Текст о стране',
            'ai_reviews_from_tourists' => 'Отзывы туристов',
        ];
    }

    public function checkAiStatus(Request $request, string $id)
    {
        $allowed = array_keys($this->getAiPrefixedDescriptionFields());
        $request->validate([
            'field' => ['required', 'string', Rule::in($allowed)],
            'languages' => ['sometimes', 'nullable'],
        ]);

        $field = (string) $request->query('field');
        $expectedCodes = $this->resolveExpectedLanguageCodes($request);

        $product = Product::with('descriptions')->findOrFail($id);

        $codeToRaw = $this->mapAiFieldValuesByLanguageCode($product, $field);
        $missingLanguages = [];

        foreach ($expectedCodes as $code) {
            $code = strtolower((string) $code);
            if (! isset($codeToRaw[$code]) || ! $this->productDescriptionAiFieldIsComplete($codeToRaw[$code])) {
                $missingLanguages[] = $code;
            }
        }

        $isReady = $expectedCodes !== [] && $missingLanguages === [];

        $startedKey = $this->aiGenerationStartedCacheKey($product->id, $field);
        $errorKey = $this->aiGenerationErrorCacheKey($product->id, $field);
        $startedAt = Cache::get($startedKey);
        $timeoutSec = (int) config('ai.generation.timeout_seconds', 300);

        $hasApiErrorFlag = Cache::has($errorKey);
        $timedOut = is_int($startedAt)
            && (time() - $startedAt) > $timeoutSec
            && ! $isReady;
        $hasFailedJob = is_int($startedAt)
            && $this->recentFailedTranslateJobMatchesProduct($product->id, $startedAt);

        $isError = $hasApiErrorFlag || $timedOut || $hasFailedJob;

        if ($isReady) {
            Cache::forget($startedKey);
            Cache::forget($errorKey);
            $status = 'success';
        } elseif ($isError) {
            $isReady = false;
            $status = 'error';
        } else {
            $status = 'processing';
        }

        return response()->json([
            'is_ready' => $isReady,
            'status' => $status,
            'missing_languages' => $missingLanguages,
        ]);
    }

    /**
     * @return list<string> нижний регистр, уникально
     */
    private function resolveExpectedLanguageCodes(Request $request): array
    {
        $fromRequest = $request->input('languages');
        if (is_string($fromRequest) && trim($fromRequest) !== '') {
            $fromRequest = array_filter(array_map('trim', explode(',', $fromRequest)));
        }
        if (! is_array($fromRequest) || $fromRequest === []) {
            $fromRequest = config('ai.generation.expected_languages', ['ru', 'en', 'he', 'ar']);
        }
        $codes = [];
        foreach ($fromRequest as $c) {
            $codes[] = strtolower((string) $c);
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, mixed> code => сырое значение колонки ai_* (для декодирования в проверке)
     */
    private function mapAiFieldValuesByLanguageCode(Product $product, string $field): array
    {
        $idToCode = Language::query()->pluck('code', 'id')->all();
        $map = [];
        foreach ($product->descriptions as $desc) {
            $code = $idToCode[$desc->language_id] ?? null;
            if ($code === null || $code === '') {
                continue;
            }
            $map[strtolower((string) $code)] = $desc->getAttribute($field);
        }

        return $map;
    }

    private function aiGenerationErrorCacheKey(int $productId, string $field): string
    {
        return 'product_ai_generation_error:'.$productId.':'.$field;
    }

    private function aiGenerationStartedCacheKey(int $productId, string $field): string
    {
        return 'product_ai_generation_started_at:'.$productId.':'.$field;
    }

    /**
     * Упавший TranslateProductJob в failed_jobs после старта текущей генерации (по времени failed_at).
     */
    private function recentFailedTranslateJobMatchesProduct(int $productId, int $startedUnix): bool
    {
        $since = Carbon::createFromTimestamp($startedUnix);

        $candidates = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(80)
            ->get(['payload']);

        foreach ($candidates as $row) {
            $payload = $row->payload ?? '';
            if (! is_string($payload) || ! str_contains($payload, 'TranslateProductJob')) {
                continue;
            }
            $pid = (string) (int) $productId;
            if (preg_match('/(?<!\d)i:'.preg_quote($pid, '/').';(?!\d)/', $payload) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Полное заполнение ai_*: JSON title/text_1/text_2 или длинный legacy-текст.
     */
    private function productDescriptionAiFieldIsComplete(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === '[]' || $value === 'null') {
            return false;
        }

        $data = json_decode((string) $value, true);
        if (is_array($data)) {
            return ! empty($data['title']) || ! empty($data['text_1']) || ! empty($data['text_2']);
        }

        return mb_strlen(trim((string) $value)) > 10;
    }
}
