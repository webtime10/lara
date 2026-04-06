<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Exceptions\RateLimitException;
use Throwable;

/**
 * Сервис для двухшаговой генерации многоязычного контента через Chat Completions API.
 *
 * Контекст использования: продукты (TranslateProductJob) и аналогичные поля вида ai_* в БД.
 * Поддерживаемые языки жёстко зашиты: ru, en, he, ar.
 *
 * Пайплайн:
 * - Шаг 1 (OpenAI): язык источника + структура { title, text_1, text_2 } на языке оригинала.
 * - Шаг 2 (OpenAI): только ru↔en (для исхода he/ar — получаем en); без JSON для he/ar.
 * - Шаг 3 (Gemini): he и ar plain-text (заголовок отдельно; text_1+text_2 с маркером ---PART---).
 *
 * Параметры из .env подхватываются через config/services.php (ключи services.openai / services.gemini).
 *
 * Шаг 2 OpenAI: только пара ru↔en (для исхода he/ar — догоняем en). Шаг 3 Gemini: he и ar plain-text.
 */
class OpenAiService
{
    protected $client;

    private const GEMINI_PART_SEPARATOR = "\n---PART---\n";

    /** База REST Gemini (со слешем на конце — дальше без двойных //). */
    private const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta/';

    /** Системное сообщение: заставляет модель отвечать только JSON (совместимо с response_format json_object). */
    private const SYSTEM_JSON_API = 'Ты — API. Возвращай только валидный JSON без пояснений.';

    public function __construct()
    {
        $this->client = OpenAI::client((string) config('services.openai.key'));
    }

    /**
     * Главная точка входа для фоновых задач перевода/генерации статей по полю продукта.
     *
     * @param  string  $rawText  Сырой текст (например из products.result или textarea админки).
     * @param  string  $targetField  Имя поля в логике промпта: влияет на стиль (статья vs «отзывы туристов»).
     *                               См. сравнение с 'ai_reviews_from_tourists' в build*Prompt.
     * @return array<string, string>|null  Карта code языка → JSON-строка для колонки в БД
     *                                    (внутри: title, text_1, text_2). null при ошибке/пустом вводе.
     */
    public function generateTranslationsForField(string $rawText, string $targetField): ?array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return null;
        }

        Log::info('[ConfigCheck] Model: '.(config('services.gemini.model') ?? 'null').' | MinChars: '.(config('services.openai.ai_article_min_chars') ?? 'null'));

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $maxOut = (int) config('services.openai.max_output_tokens', 16384);
        $minChars = (int) config('services.openai.ai_article_min_chars', 2500);

        Log::info('[OpenAiService] Step 1/2 detect + structure source', [
            'target_field' => $targetField,
            'target_chars_hint' => $minChars,
            'source_len' => mb_strlen($rawText),
        ]);

        $step1Prompt = $this->buildDetectAndStructurePrompt($targetField, $minChars)."\n\nSOURCE TEXT:\n".$rawText;
        $step1Payload = [
            'model' => $model,
            'max_tokens' => $maxOut,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_JSON_API],
                ['role' => 'user', 'content' => $step1Prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        Log::info('[OpenAiService] Step 1 request sent', [
            'target_field' => $targetField,
            'max_tokens' => $maxOut,
        ]);
        $step1 = $this->createChatCompletionWithRateLimitRetry($step1Payload, 'step1', $targetField);
        if ($step1 === null) {
            Log::error('[OpenAiService] Step 1 failed: no response', ['target_field' => $targetField]);
            return null;
        }

        $this->logCompletionUsage($step1);
        // finish_reason === 'length' значит ответ обрезан — JSON может быть битым; предупреждение в лог.
        $this->logIfTruncated($step1, 'step1');

        $structured = $this->parseStructuredSource($step1->choices[0]->message->content ?? null);
        if ($structured === null) {
            Log::error('[OpenAiService] Step 1 failed: parseStructuredSource returned null', ['target_field' => $targetField]);
            return null;
        }

        $sourceLang = $structured['source_lang'];
        $baseArticle = [
            'title' => $structured['title'],
            'text_1' => $structured['text_1'],
            'text_2' => $structured['text_2'],
        ];
        // Если модель «схалтурила» с длиной или сильно перекосила части — отбрасываем весь прогон.
        if (! $this->validateArticleSizeAndSplit($baseArticle, $minChars, 'source')) {
            return null;
        }
        Log::info('[OpenAiService] Step 1 parsed', [
            'source_lang' => $sourceLang,
            'title_len' => mb_strlen($baseArticle['title']),
            'text_1_len' => mb_strlen($baseArticle['text_1']),
            'text_2_len' => mb_strlen($baseArticle['text_2']),
        ]);

        $byLang = [$sourceLang => $baseArticle];

        // Шаг 2 (OpenAI): только ru↔en; для исходного he/ar дополнительно получаем en (без he/ar в JSON).
        $openAiTargets = $this->openAiTranslationTargets($sourceLang);
        if ($openAiTargets === []) {
            Log::info('[OpenAiService] Step 2 OpenAI пропущен (нет целей для пары ru/en).', ['source_lang' => $sourceLang]);
        } else {
            Log::info('[OpenAiService] Step 2 OpenAI (только ru/en, без he/ar)', [
                'source_lang' => $sourceLang,
                'open_ai_targets' => implode(',', $openAiTargets),
            ]);

            $step2Prompt = $this->buildTranslateFromStructuredPrompt($targetField, $sourceLang, $openAiTargets, $minChars);
            $step2Payload = [
                'model' => $model,
                'max_tokens' => $maxOut,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_JSON_API],
                    ['role' => 'user', 'content' => $step2Prompt."\n\nSOURCE_ARTICLE_JSON:\n".json_encode($baseArticle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                'response_format' => ['type' => 'json_object'],
            ];

            Log::info('[OpenAiService] Step 2 request sent', [
                'source_lang' => $sourceLang,
                'targets' => implode(',', $openAiTargets),
                'max_tokens' => $maxOut,
            ]);
            $step2 = $this->createChatCompletionWithRateLimitRetry($step2Payload, 'step2', $targetField);

            if ($step2 !== null) {
                $this->logCompletionUsage($step2);
                $this->logIfTruncated($step2, 'step2');
                $translated = $this->parseTranslationsJson($step2->choices[0]->message->content ?? null);
                if (is_array($translated)) {
                    Log::info('[OpenAiService] Step 2 parsed translations', [
                        'languages' => implode(',', array_keys($translated)),
                    ]);
                    foreach ($openAiTargets as $lang) {
                        if (! isset($translated[$lang])) {
                            continue;
                        }
                        if (! $this->validateArticleSizeAndSplit($translated[$lang], $minChars, $lang)) {
                            Log::warning('[OpenAiService] translation skipped due to size/split check', ['lang' => $lang]);
                            continue;
                        }
                        $byLang[$lang] = $translated[$lang];
                    }
                }
            } else {
                Log::warning('[OpenAiService] Step 2 failed: no response', ['target_field' => $targetField]);
            }
        }

        // Шаг 3 (Gemini): he и ar — plain text, отдельно заголовок и объединённое тело (с разбором PART).
        foreach (['he', 'ar'] as $rtlCode) {
            if ($rtlCode === $sourceLang) {
                continue;
            }
            Log::info("[Gemini] Начинаю перевод на {$rtlCode}...");
            $rtlArticle = $this->translateArticleWithGemini($baseArticle, $rtlCode);
            if ($rtlArticle === null) {
                Log::warning('[OpenAiService] Gemini: язык пропущен (API или разбор текста)', ['lang' => $rtlCode]);
                continue;
            }
            if (! $this->validateArticleSizeAndSplit($rtlArticle, $minChars, $rtlCode, $baseArticle)) {
                Log::warning('[OpenAiService] Gemini RTL: не прошла валидация', ['lang' => $rtlCode]);
                continue;
            }
            $byLang[$rtlCode] = $rtlArticle;
            Log::info("[Gemini] Перевод на {$rtlCode} получен и прошел валидацию.");
        }

        Log::info('[OpenAiService] Final language set', [
            'languages' => implode(',', array_keys($byLang)),
            'expected_all' => 'ru,en,he,ar',
        ]);
        // Каждое значение — отдельная JSON-строка для записи в LONGTEXT колонку product_descriptions.
        return $this->packLanguagesForDb($byLang);
    }

    /**
     * Промпт шага 1: детект языка + нормализация в три блока на языке оригинала.
     */
    private function buildDetectAndStructurePrompt(string $targetField, int $minChars): string
    {
        $modeHint = $targetField === 'ai_reviews_from_tourists'
            ? 'Нейтральный информативный текст без вымышленных личных отзывов.'
            : 'Информативная статья по материалу.';

        return <<<PROMPT
Сделай один структурированный текст из SOURCE TEXT.

Задача:
1) Определи язык SOURCE TEXT: строго один из ru, en, he, ar.
2) Подготовь статью на ТОМ ЖЕ языке (без перевода) в 3 полях:
   - title
   - text_1
   - text_2
{$modeHint}
Жесткое правило длины: text_1 + text_2 не меньше {$minChars} символов.
Разделение: text_1 и text_2 должны быть примерно пополам (плюс/минус около 20%).

Ответ JSON:
{
  "source_lang": "ru|en|he|ar",
  "title": "...",
  "text_1": "...",
  "text_2": "..."
}
PROMPT;
    }

    /**
     * Промпт шага 2: мультиязычный перевод уже структурированного JSON (без повторного определения языка).
     */
    private function buildTranslateFromStructuredPrompt(string $targetField, string $sourceLang, array $targets, int $minChars): string
    {
        $modeHint = $targetField === 'ai_reviews_from_tourists'
            ? 'Стиль нейтральный, без личных отзывов.'
            : 'Стиль туристической справочной статьи.';
        $targetsCsv = implode(', ', $targets);

        return <<<PROMPT
SOURCE_ARTICLE_JSON уже структурирован на языке {$sourceLang}.
Переведи его на целевые языки: {$targetsCsv}.
{$modeHint}
Сохраняй структуру полей и HTML.
Соблюдай длину: text_1 + text_2 >= {$minChars}, и дели примерно пополам (плюс/минус 20%).

Ответ JSON:
{
  "translations": {
    "<lang>": {"title":"...","text_1":"...","text_2":"..."}
  }
}
Где <lang> только из списка: {$targetsCsv}.
PROMPT;
    }

    /** Разбор ответа шага 1; жёсткая проверка source_lang против белого списка. */
    private function parseStructuredSource(?string $json): ?array
    {
        $data = json_decode($json ?? '', true);
        if (! is_array($data)) {
            Log::error('[OpenAiService] step1 invalid json', ['json' => $json]);
            return null;
        }
        $lang = $data['source_lang'] ?? null;
        if (! in_array($lang, ['ru', 'en', 'he', 'ar'], true)) {
            Log::error('[OpenAiService] step1 invalid source_lang', ['json' => $json]);
            return null;
        }
        return [
            'source_lang' => $lang,
            'title' => trim((string) ($data['title'] ?? '')),
            'text_1' => trim((string) ($data['text_1'] ?? '')),
            'text_2' => trim((string) ($data['text_2'] ?? '')),
        ];
    }

    /** Разбор ответа шага 2: ожидается обёртка { "translations": { "en": {...}, ... } }. */
    private function parseTranslationsJson(?string $json): ?array
    {
        $decoded = json_decode($json ?? '', true);
        if (! is_array($decoded) || ! isset($decoded['translations']) || ! is_array($decoded['translations'])) {
            Log::error('[OpenAiService] step2 invalid translations json', ['json' => $json]);
            return null;
        }
        $out = [];
        foreach (['ru', 'en', 'he', 'ar'] as $lang) {
            $row = $decoded['translations'][$lang] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $out[$lang] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'text_1' => trim((string) ($row['text_1'] ?? '')),
                'text_2' => trim((string) ($row['text_2'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Бизнес-правило качества: суммарная длина и баланс частей.
     * Для he/ar при $referenceArticle — мягче: длина относительно оригинала ±40%, баланс 10–90%.
     *
     * @param  array{title?:string,text_1:string,text_2:string}  $article
     * @param  array{title?:string,text_1:string,text_2:string}|null  $referenceArticle  оригинал (base) для RTL
     */
    private function validateArticleSizeAndSplit(array $article, int $minChars, string $lang, ?array $referenceArticle = null): bool
    {
        $l1 = mb_strlen((string) ($article['text_1'] ?? ''));
        $l2 = mb_strlen((string) ($article['text_2'] ?? ''));
        $sum = $l1 + $l2;

        $isRtl = ($lang === 'he' || $lang === 'ar') && $referenceArticle !== null;
        if ($isRtl) {
            $refL1 = mb_strlen((string) ($referenceArticle['text_1'] ?? ''));
            $refL2 = mb_strlen((string) ($referenceArticle['text_2'] ?? ''));
            $refSum = $refL1 + $refL2;
            if ($refSum > 0) {
                $low = $refSum * 0.6;
                $high = $refSum * 1.4;
                if ($sum < $low || $sum > $high) {
                    Log::warning('[OpenAiService] RTL article length vs reference out of ±40%', [
                        'lang' => $lang,
                        'sum' => $sum,
                        'ref_sum' => $refSum,
                        'allowed' => [$low, $high],
                    ]);

                    return false;
                }
            } elseif ($sum < $minChars) {
                Log::warning('[OpenAiService] RTL article too short (no ref sum)', [
                    'lang' => $lang,
                    'sum' => $sum,
                    'required_min' => $minChars,
                ]);

                return false;
            }
            $ratio = $sum > 0 ? $l1 / $sum : 0.0;
            if ($ratio < 0.10 || $ratio > 0.90) {
                Log::warning('[OpenAiService] RTL article split is not balanced', [
                    'lang' => $lang,
                    'text_1_len' => $l1,
                    'text_2_len' => $l2,
                    'ratio_text_1' => $ratio,
                    'expected_range' => '0.10..0.90',
                ]);

                return false;
            }

            return true;
        }

        if ($sum < $minChars) {
            Log::warning('[OpenAiService] article too short', [
                'lang' => $lang,
                'text_1_len' => $l1,
                'text_2_len' => $l2,
                'sum' => $sum,
                'required_min' => $minChars,
            ]);

            return false;
        }
        $ratio = $sum > 0 ? $l1 / $sum : 0.0;
        if ($ratio < 0.30 || $ratio > 0.70) {
            Log::warning('[OpenAiService] article split is not balanced', [
                'lang' => $lang,
                'text_1_len' => $l1,
                'text_2_len' => $l2,
                'ratio_text_1' => $ratio,
                'expected_range' => '0.30..0.70',
            ]);

            return false;
        }

        return true;
    }

    /**
     * OpenAI шаг 2: только «латинница» ru↔en; для he/ar исходника — добираем en (he/ar в JSON не просим).
     *
     * @return list<string>
     */
    private function openAiTranslationTargets(string $sourceLang): array
    {
        return match ($sourceLang) {
            'ru' => ['en'],
            'en' => ['ru'],
            'he', 'ar' => ['en'],
            default => ['en'],
        };
    }

    /**
     * Полный перевод статьи на he/ar через Gemini (заголовок отдельно; text_1+text_2 одним запросом с разделителем).
     *
     * @param  array{title:string,text_1:string,text_2:string}  $baseArticle
     * @return array{title:string,text_1:string,text_2:string}|null
     */
    private function translateArticleWithGemini(array $baseArticle, string $rtlCode): ?array
    {
        $targetLang = $rtlCode === 'he' ? 'Hebrew' : 'Arabic';

        $titleOut = $this->callGeminiApi((string) $baseArticle['title'], $targetLang, $rtlCode);
        if ($titleOut === null || trim($titleOut) === '') {
            return null;
        }

        $bodyIn =
            'The input has TWO sections separated by a single line containing exactly ---PART--- (three hyphens, word PART, three hyphens). '
            ."Translate BOTH sections to {$targetLang}. In your output, put exactly the same ---PART--- line between the translated first and second section. No commentary before or after.\n\n"
            .trim((string) $baseArticle['text_1'])
            .self::GEMINI_PART_SEPARATOR
            .trim((string) $baseArticle['text_2']);

        $bodyOut = $this->callGeminiApi($bodyIn, $targetLang, $rtlCode);
        if ($bodyOut === null || trim($bodyOut) === '') {
            return null;
        }

        $split = preg_split('/\R?---PART---\R?/u', $bodyOut, 2);
        if ($split === false || count($split) < 2) {
            Log::warning('[OpenAiService] Gemini body: не удалось разрезать по ---PART---', ['lang' => $rtlCode]);

            return null;
        }

        return [
            'title' => trim($titleOut),
            'text_1' => trim((string) ($split[0] ?? '')),
            'text_2' => trim((string) ($split[1] ?? '')),
        ];
    }

    /**
     * ID модели для сегмента .../models/{id}:generateContent — без префикса models/, слешей и кавычек (без двойных // в URL).
     */
    private function sanitizeGeminiModelId(string $raw): string
    {
        $s = trim($raw);
        $s = trim($s, " \t\n\r\0\x0B\"'");
        $s = trim($s, '/');
        if (str_starts_with($s, 'models/')) {
            $s = substr($s, strlen('models/'));
        }
        $s = trim($s, '/');
        $s = preg_replace('#/+#', '/', $s) ?? $s;
        $s = trim($s, '/');

        return $s;
    }

    /**
     * Прямой HTTP-запрос к Gemini generateContent (без JSON от модели — только текст).
     * URL: {GEMINI_API_BASE}models/{$model}:generateContent?key={$key}
     */
    public function callGeminiApi(string $text, string $targetLang, string $lang): ?string
    {
        $key = config('services.gemini.key');
        if ($key === null || $key === '') {
            Log::error('[Gemini] Ошибка перевода на '.$lang.': GEMINI_API_KEY не задан');

            return null;
        }
        $key = trim((string) $key, " \t\n\r\0\x0B\"'");

        $model = $this->sanitizeGeminiModelId((string) config('services.gemini.model', 'gemini-2.5-flash'));
        if ($model === '') {
            Log::error('[Gemini] Ошибка перевода на '.$lang.': GEMINI_MODEL после нормализации пустой');

            return null;
        }

        $path = 'models/'.$model.':generateContent';
        $base = self::GEMINI_API_BASE;
        $urlWithoutKey = $base.$path;
        Log::info('[Gemini] Запрос к API: '.$urlWithoutKey);

        $url = $urlWithoutKey.'?key='.urlencode($key);

        $userText = "Translate the following text to {$targetLang}. Return ONLY the translation, no markers, no chat.\n\n".$text;

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'contents' => [['parts' => [['text' => $userText]]]],
                ]);

            if (! $response->successful()) {
                Log::error('[Gemini] Ошибка перевода на '.$lang.': HTTP '.$response->status().' '.$response->body());

                return null;
            }

            $out = $response->json('candidates.0.content.parts.0.text');
            if (! is_string($out)) {
                Log::error('[Gemini] Ошибка перевода на '.$lang.': пустой или нестроковый ответ API');

                return null;
            }

            return trim($out);
        } catch (Throwable $e) {
            Log::error('[Gemini] Ошибка перевода на '.$lang.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * Сериализация под хранение в MySQL: одна строка JSON на язык (pretty print для читаемости в админке).
     *
     * @param  array<string, array{title:string,text_1:string,text_2:string}>  $byLang
     */
    private function packLanguagesForDb(array $byLang): ?array
    {
        $result = [];
        foreach (['ru', 'en', 'he', 'ar'] as $lang) {
            $row = $byLang[$lang] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $encoded = json_encode([
                'title' => $row['title'] ?? '',
                'text_1' => $row['text_1'] ?? '',
                'text_2' => $row['text_2'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($encoded !== false) {
                $result[$lang] = $encoded;
            }
        }
        return count($result) > 0 ? $result : null;
    }

    /** Диагностика стоимости/объёма запроса (токены в логах). */
    private function logCompletionUsage(mixed $response): void
    {
        $usage = $response->usage ?? null;
        if ($usage === null) {
            return;
        }
        Log::info('[OpenAiService] usage', [
            'prompt_tokens' => $usage->promptTokens ?? null,
            'completion_tokens' => $usage->completionTokens ?? null,
            'total_tokens' => $usage->totalTokens ?? null,
        ]);
    }

    /** Предупреждение, если модель уперлась в max_tokens — ответ может быть невалидным JSON. */
    private function logIfTruncated(mixed $response, string $stepLabel): void
    {
        $finish = $response->choices[0]?->finishReason ?? null;
        if ($finish === 'length') {
            Log::warning("[OpenAiService] Ответ обрезан по лимиту токенов ({$stepLabel}). Увеличьте OPENAI_MAX_OUTPUT_TOKENS в .env.");
        }
    }

    /**
     * Вызов chat()->create с экспоненциальной задержкой при 429.
     * Прочие исключения не пробрасываются — вызывающий код получает null (частичная деградация по шагам).
     */
    private function createChatCompletionWithRateLimitRetry(array $payload, string $step, string $targetField): mixed
    {
        $maxAttempts = (int) config('services.openai.rate_limit_retries', 8);
        $baseWait = (int) config('services.openai.rate_limit_wait_base_sec', 10);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->client->chat()->create($payload);
            } catch (RateLimitException $e) {
                // Линейное наращивание паузы: baseWait * attempt секунд между попытками одного шага.
                $wait = $baseWait * $attempt;
                Log::warning("[OpenAiService] Лимит API (429). Попытка {$attempt}. Ждем {$wait} сек.", ['step' => $step, 'target_field' => $targetField]);
                sleep($wait);
            } catch (\Throwable $e) {
                Log::error('[OpenAiService] Ошибка: '.$e->getMessage(), ['step' => $step]);

                return null;
            }
        }

        return null;
    }
}
