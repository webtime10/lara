<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Exceptions\RateLimitException;

class OpenAiService
{
    protected $client;

    private const SYSTEM_JSON_API = 'Ты — API. Возвращай только валидный JSON без пояснений.';

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    /**
     * Берёт исходный текст (из result/source_text), определяет язык источника,
     * и возвращает JSON для ru/en/he/ar (title, text_1, text_2), где язык источника не переводится.
     */
    public function generateTranslationsForField(string $rawText, string $targetField): ?array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return null;
        }

        $model = env('OPENAI_MODEL', 'gpt-4o-mini');
        $maxOut = (int) env('OPENAI_MAX_OUTPUT_TOKENS', 16384);
        $minChars = max(1000, (int) env('OPENAI_AI_ARTICLE_MIN_CHARS', 4500));

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
        if (! $this->validateArticleSizeAndSplit($baseArticle, $minChars, 'source')) {
            return null;
        }
        Log::info('[OpenAiService] Step 1 parsed', [
            'source_lang' => $sourceLang,
            'title_len' => mb_strlen($baseArticle['title']),
            'text_1_len' => mb_strlen($baseArticle['text_1']),
            'text_2_len' => mb_strlen($baseArticle['text_2']),
        ]);

        $targets = array_values(array_diff(['ru', 'en', 'he', 'ar'], [$sourceLang]));
        if ($targets === []) {
            return $this->packLanguagesForDb([$sourceLang => $baseArticle]);
        }

        Log::info('[OpenAiService] Step 2/2 translate missing languages', [
            'source_lang' => $sourceLang,
            'targets' => implode(',', $targets),
        ]);

        $step2Prompt = $this->buildTranslateFromStructuredPrompt($targetField, $sourceLang, $targets, $minChars);
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
            'targets' => implode(',', $targets),
            'max_tokens' => $maxOut,
        ]);
        $step2 = $this->createChatCompletionWithRateLimitRetry($step2Payload, 'step2', $targetField);
        $byLang = [$sourceLang => $baseArticle];

        if ($step2 !== null) {
            $this->logCompletionUsage($step2);
            $this->logIfTruncated($step2, 'step2');
            $translated = $this->parseTranslationsJson($step2->choices[0]->message->content ?? null);
            if (is_array($translated)) {
                Log::info('[OpenAiService] Step 2 parsed translations', [
                    'languages' => implode(',', array_keys($translated)),
                ]);
                foreach ($targets as $lang) {
                    if (isset($translated[$lang])) {
                        if (! $this->validateArticleSizeAndSplit($translated[$lang], $minChars, $lang)) {
                            Log::warning('[OpenAiService] translation skipped due to size/split check', ['lang' => $lang]);
                            continue;
                        }
                        $byLang[$lang] = $translated[$lang];
                    }
                }
            }
        } else {
            Log::warning('[OpenAiService] Step 2 failed: no response', ['target_field' => $targetField]);
        }

        Log::info('[OpenAiService] Final language set', [
            'languages' => implode(',', array_keys($byLang)),
        ]);
        return $this->packLanguagesForDb($byLang);
    }

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
     * @param array{title:string,text_1:string,text_2:string} $article
     */
    private function validateArticleSizeAndSplit(array $article, int $minChars, string $lang): bool
    {
        $l1 = mb_strlen((string) ($article['text_1'] ?? ''));
        $l2 = mb_strlen((string) ($article['text_2'] ?? ''));
        $sum = $l1 + $l2;
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
     * @param array<string, array{title:string,text_1:string,text_2:string}> $byLang
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

    private function logIfTruncated(mixed $response, string $stepLabel): void
    {
        $finish = $response->choices[0]?->finishReason ?? null;
        if ($finish === 'length') {
            Log::warning("[OpenAiService] Ответ обрезан по лимиту токенов ({$stepLabel}). Увеличьте OPENAI_MAX_OUTPUT_TOKENS в .env.");
        }
    }

    private function createChatCompletionWithRateLimitRetry(array $payload, string $step, string $targetField): mixed
    {
        $maxAttempts = (int) env('OPENAI_RATE_LIMIT_RETRIES', 8);
        $baseWait = (int) env('OPENAI_RATE_LIMIT_WAIT_BASE_SEC', 10);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->client->chat()->create($payload);
            } catch (RateLimitException $e) {
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
