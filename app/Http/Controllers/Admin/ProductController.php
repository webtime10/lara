<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Jobs\AiFieldGeneratorJob;
use App\Models\Category;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Models\User;
use App\Support\AiDescriptionJsonNormalizer;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Smalot\PdfParser\Parser;
use Throwable;

class ProductController extends Controller
{
    // Трейт с логикой нормализации slug'ов для локализованных полей (общий для разных форм).
    use NormalizesLocalizedSlugs;


    /**
     * POST admin/products/generate-ai: ставит в очередь AiFieldGeneratorJob (по одному на язык).
     * Подробности по шагам — в комментариях к строкам тела метода.
     */
public function generateAi(Request $request)
    {
        // 1. СРАЗУ ЛОГИРУЕМ НАЧАЛО (Чтобы ты видел в tail -f, что запрос пришел)
        \Log::info('--- AI Generation Request Started ---', [   // для отображения echo в логах
            'product_id'  => $request->input('product_id'),
            'ai_field'    => $request->input('ai_field'),
            'has_result'  => !empty($request->input('result_text')),
            'has_source'  => !empty($request->input('source_text')),
        ]);
// светафор
        $aiFields = array_keys($this->getAiPrefixedDescriptionFields());

        // Валидация
        $data = $request->validate([
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'source_text' => ['nullable', 'string'],
            'result_text' => ['nullable', 'string'],
            'ai_field'    => ['nullable', 'string', Rule::in($aiFields)],
        ]);


        // SELECT * FROM `products` WHERE `id` = 125 LIMIT 1;
        $product = Product::findOrFail($data['product_id']);

        // 2. Исходный текст: Source и Result из AJAX → затем БД → запасные варианты.
        $baseText = trim((string) ($data['source_text'] ?? ''));
        if ($baseText === '') {
            $baseText = trim((string) ($data['result_text'] ?? ''));
        }
        if ($baseText === '') {
            $defaultLang = Language::getDefault();
            if ($defaultLang) {
                $baseText = trim((string) DB::table('product_descriptions')
                    ->where('product_id', $product->id)
                    ->where('language_id', $defaultLang->id)
                    ->value('result'));
            }
        }
        if ($baseText === '') {
            $baseText = trim((string) ($product->result ?? ''));
        }
        if ($baseText === '') {
            $baseText = trim((string) ($product->source_text ?? ''));
        }

        // ДОПОЛНИТЕЛЬНЫЙ ШАНС: Если всё еще пусто, берем текст из описания товара (RU)
        if ($baseText === '') {
            $defaultLang = \App\Models\Language::getDefault();
            $desc = $product->descriptions()->where('language_id', $defaultLang?->id)->first();
            // Чистим от тегов, если там HTML
            $baseText = trim(strip_tags((string) ($desc?->description ?? '')));
            
            if ($baseText !== '') {
                \Log::info('Source text found in Product Description (fallback).');
            }
        }

        // 3. ПРОВЕРКА ДЛИНЫ (снизили до 5 символов, чтобы не блокировать короткие описания)
        if (mb_strlen($baseText) < 5) {
            \Log::warning('AI Generation ABORTED: No sufficient source text found.', ['text' => $baseText]);
            return response()->json([
                'message' => 'Не нашли текст для генерации. Заполните поле «Исходное сырьё» или «Результат», сохраните пост или задайте описание.',
            ], 422);
        }

        // Если поле не выбрано — генерируем сразу все ai_*.  // сбор всех полей
        $targetFields = [];

        $targetFields = $aiFields;
     
        if ($targetFields === []) {
            return response()->json([
                'message' => 'Не найдены AI поля для генерации.',
            ], 422);
        }
// в светафор машина завелась - значение 0 закидываем
        $product->update(['ai_status' => json_encode(0)]);
/*
*/
        foreach ($targetFields as $targetField) {
            Cache::forget($this->aiGenerationErrorCacheKey($product->id, $targetField)); // полностью удаляем запись из кеша
            DB::table('product_descriptions')
                ->where('product_id', $product->id)
                ->update([$targetField => null]);
            // Ставим метку старта для каждого поля отдельно.
            Cache::put($this->aiGenerationStartedCacheKey($product->id, $targetField), time(), 86400);  // зписывет nullв кеш
        }  // мы каждые 5 секунд рьрощаемся сюда с аякс получаем состояние  (это желтый)

        $languages = Language::all();

        \Log::info('Dispatching AiFieldGeneratorJob workers to Queue...', [
            'product_id' => $product->id,
            'manufacturer_id' => $product->manufacturer_id,
            'fields' => $targetFields,
            'char_count' => mb_strlen($baseText),
            'languages_count' => $languages->count(),
        ]);

 


/// здесь отдаю в воркер
foreach ($targetFields as $targetField) {
    foreach ($languages as $language) {
        // 1. Проверка существования записи в целевой таблице
        $exists = DB::table('product_descriptions')
            ->where('product_id', $product->id)
            ->where('language_id', $language->id)
            ->exists();

        if (!$exists) {
            \Log::warning('[generateAi] Пропуск: нет записи в product_descriptions', [
                'product_id' => $product->id, 
                'lang' => $language->id, 
                'field' => $targetField
            ]);
            continue;
        }

        // 2. Выбор правильного промпта (твой умный SQL)
        $mid = $product->manufacturer_id;
        $sql = $mid !== null ? '
            SELECT d.*, c.id AS resolved_prompt_category_id
            FROM `prompt_category_descriptions` AS d
            INNER JOIN `prompt_categories` AS c ON d.prompt_category_id = c.id
            WHERE c.ai_field = ? AND d.language_id = ?
              AND (c.manufacturer_id IS NULL OR c.manufacturer_id = ?)
            ORDER BY 
                CASE WHEN c.manufacturer_id <=> ? THEN 0 WHEN c.manufacturer_id IS NULL THEN 1 ELSE 2 END,
                c.sort_order ASC, c.id ASC
            LIMIT 1' : '
            SELECT d.*, c.id AS resolved_prompt_category_id
            FROM `prompt_category_descriptions` AS d
            INNER JOIN `prompt_categories` AS c ON d.prompt_category_id = c.id
            WHERE c.ai_field = ? AND d.language_id = ? AND c.manufacturer_id IS NULL
            ORDER BY c.sort_order ASC, c.id ASC
            LIMIT 1';

        $params = $mid !== null ? [$targetField, $language->id, $mid, $mid] : [$targetField, $language->id];
        $prompts = DB::selectOne($sql, $params);

        if (!$prompts) {
            \Log::error("КРИТИЧЕСКАЯ ОШИБКА: Промпт не найден для поля {$targetField} и языка {$language->id}.");
            continue;
        }

        // 3. Отправка в очередь с передачей всех данных
        // Мы передаем объект $prompts, который уже привязан к нужному language_id
        dispatch(new AiFieldGeneratorJob(
            $product,
            $language->id,
            $targetField,
            $baseText,
            $prompts
        ));
        
        \Log::info('[generateAi] Задача отправлена в очередь', [
            'product_id' => $product->id,
            'lang' => $language->id,
            'field' => $targetField,
            'prompt_id' => $prompts->id
        ]);
    }
}

        return response()->json([
            'message' => count($targetFields) > 1
                ? 'Генерация всех AI-полей успешно запущена в фоне.'
                : 'Генерация успешно запущена в фоне.',
        ]);
    }
/*
*/
    /**
     *  методы для пробразования в текст
     * POST admin/products/extract-text: извлечение текста из PDF / DOCX / TXT для поля «сырьё».
     */
/*
1. Серый (Состояние покоя)
В кэше: Пусто.

В базе (поле описания): Пусто (NULL) или старый текст.

Статус: Ничего не происходит.

2. Желтый (Твой "0" и "time")
Как только ты нажал кнопку:

В базу (ai_status): Ты записываешь 0. Это сигнал: «Машина завелась».

В кэш: Ты записываешь метку времени time().

Логика проверки: Пока в кэше есть эта метка времени, твой метод checkAiStatus будет отдавать is_ready: false.

Фронтенд: Видит false и держит жёлтый спиннер.

3. Зеленый (Финал)
Зеленый загорается тогда, когда выполняются два условия одновременно:

В базе появилось «не NULL»: Воркер (Job) записал туда готовый текст от OpenAI.

В кэше стало пусто: Воркер выполнил команду Cache::forget.

То есть, "Зеленый" — это отсутствие метки в кэше ПРИ НАЛИЧИИ текста в базе.

*/
/*
$product (Весь товар целиком)

Это объект. Воркер будет знать всё: его ID, цену, название и текущие связи.

Зачем это нужно воркеру? Чтобы он знал, в какую строку какой таблицы записывать готовый результат.

$language->id (Конкретный язык) (заходят по очерди все языки котрые есть)

Помнишь, у тебя там цикл? Так вот, один воркер берет только один язык.

Зачем это нужно? Воркер скажет нейронке: «Эй, напиши мне этот текст именно на немецком (или английском)».

$targetField (Конкретное поле — твоя "цель")

Например, ai_reviews_from_tourists или ai_country_description.

Зачем это нужно? Это «адрес» внутри таблицы product_descriptions. Воркер должен точно знать, в какую «ячейку» положить готовый текст, чтобы не затереть что-то другое.

$baseText (Сырые данные — твой "залетевший" текст)

Это то, что ты выудил из PDF или из описания товара. Это «топливо» для нейронки.

Зачем это нужно? Это и есть основа промпта. Воркер скажет: «Возьми вот этот сырой текст и сделай из него конфетку».

*/


    public function extractText(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:15360'],
        ]);

        $uploaded = $request->file('file');
        $ext = strtolower((string) $uploaded->getClientOriginalExtension());

        if (! in_array($ext, ['pdf', 'docx', 'txt'], true)) {
            return response()->json(['message' => 'Допустимые форматы: PDF, DOCX, TXT.'], 422);
        }

        $path = $uploaded->getRealPath();
        if ($path === false || ! is_readable($path)) {
            return response()->json(['message' => 'Не удалось прочитать загруженный файл.'], 422);
        }

        try {
            $raw = match ($ext) {
                'pdf' => $this->extractTextFromPdfPath($path),
                'docx' => $this->extractTextFromDocxPath($path),
                'txt' => $this->extractTextFromTxtPath($path),
                default => '',
            };
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Ошибка разбора файла: '.$e->getMessage(),
            ], 422);
        }

        $text = $this->normalizeExtractedTextToUtf8((string) $raw);

        return response()->json(
            ['text' => $text],
            200,
            ['Content-Type' => 'application/json; charset=UTF-8'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function extractTextFromPdfPath(string $path): string
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($path);

        return $pdf->getText();
    }

    private function extractTextFromDocxPath(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $buffer = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $buffer .= $this->extractTextFromPhpWordElement($element);
            }
        }

        return $buffer;
    }

    private function extractTextFromPhpWordElement(mixed $element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }

        if ($element instanceof TextRun) {
            $parts = '';
            foreach ($element->getElements() as $child) {
                $parts .= $this->extractTextFromPhpWordElement($child);
            }

            return $parts;
        }

        if ($element instanceof Title) {
            $inner = $element->getText();
            if (is_string($inner)) {
                return $inner."\n";
            }

            return $this->extractTextFromPhpWordElement($inner)."\n";
        }

        if ($element instanceof PreserveText) {
            return $element->getText();
        }

        if ($element instanceof Table) {
            $block = '';
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellText = '';
                    foreach ($cell->getElements() as $cellEl) {
                        $cellText .= $this->extractTextFromPhpWordElement($cellEl);
                    }
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $cellText));
                }
                $block .= implode("\t", $cells)."\n";
            }

            return $block;
        }

        if ($element instanceof AbstractContainer) {
            $acc = '';
            foreach ($element->getElements() as $child) {
                $acc .= $this->extractTextFromPhpWordElement($child);
            }

            return $acc;
        }

        if (is_object($element) && method_exists($element, 'getText')) {
            $inner = $element->getText();
            if (is_string($inner)) {
                return $inner;
            }
            if (is_object($inner)) {
                return $this->extractTextFromPhpWordElement($inner);
            }
        }

        if (is_object($element) && method_exists($element, 'getElements')) {
            $acc = '';
            foreach ($element->getElements() as $child) {
                $acc .= $this->extractTextFromPhpWordElement($child);
            }

            return $acc;
        }

        return '';
    }

    private function extractTextFromTxtPath(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Пустой или недоступный TXT.');
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        return $raw;
    }

    private function normalizeExtractedTextToUtf8(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $detected = mb_detect_encoding($raw, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'CP1252'], true);
            if ($detected !== false && $detected !== 'UTF-8') {
                $converted = mb_convert_encoding($raw, 'UTF-8', $detected);
                if ($converted !== false) {
                    $raw = $converted;
                }
            } else {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
            }
        }

        return $raw;
    }
    /**
     *  методы для пробразования в текст
     * POST admin/products/extract-text: извлечение текста из PDF / DOCX / TXT для поля «сырьё».
     */
    public function index(Request $request)
    {
        $pageTitle = 'Посты';
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
        $pageTitle = 'Пост — создание';
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
        $aiFieldKeys = array_keys($this->getAiPrefixedDescriptionFields());
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
            foreach ($aiFieldKeys as $aiField) {
                $rules[$aiField.'_'.$suffix] = 'nullable|string';
            }
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $aiFieldKeys) {
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
                $descriptionPayload = [
                    'product_id' => $product->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'tag' => $request->input('tag_'.$suffix),
                    'meta_title' => $request->input('meta_title_'.$suffix),
                    'meta_description' => $request->input('meta_description_'.$suffix),
                    'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                    'result' => $this->resolveProductDescriptionSourceRaw($request),
                ];
                foreach ($aiFieldKeys as $aiField) {
                    $descriptionPayload[$aiField] = AiDescriptionJsonNormalizer::normalize(
                        $request->input($aiField.'_'.$suffix)
                    );
                }
                ProductDescription::create($descriptionPayload);
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Пост создан');
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
        $aiFieldKeys = array_keys($this->getAiPrefixedDescriptionFields());
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
            foreach ($aiFieldKeys as $aiField) {
                $rules[$aiField.'_'.$suffix] = 'nullable|string';
            }
        }

        // Обработка автоматических слагов перед валидацией
        $this->mergeLocalizedSlugsFromRequest($request, $languages);

        $request->validate($rules);

        // Все изменения в БД оборачиваем в транзакцию — либо всё сохранится, либо ничего
        DB::transaction(function () use ($request, $languages, $product, $aiFieldKeys) {
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
                $descriptionPayload = [
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'tag' => $request->input('tag_'.$suffix),
                    'meta_title' => $request->input('meta_title_'.$suffix),
                    'meta_description' => $request->input('meta_description_'.$suffix),
                    'meta_keyword' => $request->input('meta_keyword_'.$suffix),
                    'result' => $this->resolveProductDescriptionSourceRaw($request),
                ];
                foreach ($aiFieldKeys as $aiField) {
                    $descriptionPayload[$aiField] = AiDescriptionJsonNormalizer::normalize(
                        $request->input($aiField.'_'.$suffix)
                    );
                }
                ProductDescription::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'language_id' => $language->id,
                    ],
                    $descriptionPayload
                );
            }
        });

        return redirect()->route('admin.products.index')->with('success', 'Пост обновлён');
    }

    public function destroy(string $id)
    {
        Product::findOrFail($id)->delete();

        return redirect()->route('admin.products.index')->with('success', 'Пост удалён');
    }

    /**
     * Колонка product_descriptions.result: приоритет у «Исходное сырьё» (source_text), если пусто — буфер «Результат» (поле result у товара).
     */
    private function resolveProductDescriptionSourceRaw(Request $request): ?string
    {
        $src = trim((string) $request->input('source_text', ''));
        if ($src !== '') {
            return $request->input('source_text');
        }

        $buf = trim((string) $request->input('result', ''));
        if ($buf !== '') {
            return $request->input('result');
        }

        return null;
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
        return ProductDescription::aiFieldLabels();
    }

    public function checkAiStatus(Request $request, string $id)
    {
      //  Ты берешь список всех твоих AI-полей (тот самый «Золотой стандарт»). Это нужно, чтобы сервер не пытался проверить статус поля, которого не существует.
        $allowed = array_keys($this->getAiPrefixedDescriptionFields());
        $request->validate([
            'field' => ['sometimes', 'nullable', 'string', Rule::in($allowed)],
            'languages' => ['sometimes', 'nullable'],
        ]);
//Ты смотришь, пришел ли запрос на проверку одного конкретного поля или всех сразу.
        $field = (string) $request->query('field');
        $expectedCodes = $this->resolveExpectedLanguageCodes($request);


        //Если ты в JS передал конкретное поле, сервер мгновенно вызывает buildAiFieldStatusPayload. Тот заглядывает в кэш и базу, и ты сразу возвращаешь ответ. Это работает быстро.
        $product = Product::with('descriptions')->findOrFail($id);
        if ($field !== '') {
            $single = $this->buildAiFieldStatusPayload($product, $field, $expectedCodes);
            return response()->json($single);
        }

        $fields = [];
        $hasError = false;
        $allReady = true;

        foreach ($allowed as $allowedField) {
            $payload = $this->buildAiFieldStatusPayload($product, $allowedField, $expectedCodes);
            $fields[$allowedField] = $payload;
            $hasError = $hasError || ($payload['status'] === 'error');
            $allReady = $allReady && ($payload['is_ready'] === true);
        }

        $status = $allReady ? 'success' : ($hasError ? 'error' : 'processing');

        return response()->json([
            'is_ready' => $allReady,
            'status' => $status,
            'fields' => $fields,
            'timeout_seconds' => (int) config('ai.generation.timeout_seconds', 3600),
        ]);
    }

    private function buildAiFieldStatusPayload(Product $product, string $field, array $expectedCodes): array
    {
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
            && $this->recentFailedAiFieldGeneratorJobMatchesProduct($product->id, $startedAt);

        $isError = $hasApiErrorFlag || $timedOut || $hasFailedJob;

        $errorReason = null;
        if ($isError) {
            if ($hasFailedJob) {
                $errorReason = 'failed_job';
            } elseif ($timedOut) {
                $errorReason = 'timeout';
            } elseif ($hasApiErrorFlag) {
                $errorReason = 'api_error_cache';
            }
        }

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

        return [
            'is_ready' => $isReady,
            'status' => $status,
            'missing_languages' => $missingLanguages,
            'error_reason' => $errorReason,
            'timeout_seconds' => $timeoutSec,
            'started_at' => $startedAt,
        ];
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
     * Упавший AiFieldGeneratorJob в failed_jobs после старта текущей генерации (по времени failed_at).
     */
    private function recentFailedAiFieldGeneratorJobMatchesProduct(int $productId, int $startedUnix): bool
    {
        $since = Carbon::createFromTimestamp($startedUnix);

        $candidates = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(80)
            ->get(['payload']);

        foreach ($candidates as $row) {
            $payload = $row->payload ?? '';
            if (! is_string($payload) || ! str_contains($payload, 'AiFieldGeneratorJob')) {
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
