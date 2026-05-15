<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\UnserializableResponse;
use RuntimeException;
use Throwable;

/**
 * Запросы к OpenAI: промпт + сырьё → ответ (см. AiFieldGeneratorJob).
 * Поддерживается несколько ключей (OPENAI_API_KEY + OPENAI_API_KEYS); при 401/403 пробуется следующий.
 */
class OpenAiService
{
    /**
     * @deprecated Используйте collectApiKeys(); оставлено для совместимости.
     */
    public function getRandomKey(): string   //берёт список ключей → возвращает случайный
    {
        $keys = $this->collectApiKeys();

        return $keys === [] ? '' : (string) $keys[array_rand($keys)];
    }

    /**
     * Уникальные ключи: сначала `services.openai.key`, затем из `keys_csv`.
     *
     * @return list<string>
     */
    public function collectApiKeys(): array
    {
        $seen = [];   // "массив для отслеживания уже добавленных ключей (чтобы не было дублей)"
        $out = [];    // "итоговый массив ключей"
        $push = function (string $k) use (&$seen, &$out): void {
            $k = trim($k, " \t\n\r\0\x0B\"'");  // "очищаем ключ от пробелов и кавычек"
            if ($k === '' || isset($seen[$k])) {
                return;
            }
           // "если ключ пустой или уже добавлялся → пропускаем"

            $seen[$k] = true;
               // "запоминаем ключ как уже добавленный"

            $out[] = $k;
            // "добавляем в итоговый список"
        };

        $push((string) config('services.openai.key', ''));
         // "добавляем основной ключ (если есть)"
        $csv = (string) config('services.openai.keys_csv', '');
           // "берём строку ключей через запятую"
        foreach (explode(',', $csv) as $part) {
            $push(trim($part));
        }

        return $out;   // "возвращаем список уникальных, очищенных ключей"
    }
//основной метод, через который ты отправляешь данные в OpenAI и получаешь ответ в воркере

    public function askOpenAi(string $prompt, string $sourceText, string $logCallSite = 'askOpenAi'): ?string
    {
        $prompt = trim($prompt);
        $sourceText = trim($sourceText);
//"очищаем входные данные (промпт и текст)"
        if ($prompt === '' || $sourceText === '') {
            Log::warning('[OpenAiService] askOpenAi: empty prompt or source text');

            return null;
        }
  // "если нет промпта или текста → не вызываем API"
        $this->logPipelineMaterial($logCallSite, $prompt, $sourceText);
// "логируем, что именно отправляем в AI (для отладки)"
        $model = $this->resolvedModel();
        $maxOut = (int) config('services.openai.max_output_tokens', 16384);
        $userContent = $prompt."\n\n--- SOURCE TEXT ---\n".$sourceText;
 // "собираем финальный текст: инструкция + разделитель + сырьё"


        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Следуй инструкциям пользователя точно. Возвращай только запрошенный результат, без пояснений «как модель», если явно не просят обратное.',
                ],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];
        $outputLimitKey = $this->applyOutputTokenLimit($payload, $maxOut);
 // "формируем запрос в формате Chat API"
        Log::info('[OpenAiService] askOpenAi request', [
            'model' => $model,
            'output_limit_key' => $outputLimitKey,
            'output_limit' => $maxOut,
            'prompt_len' => mb_strlen($prompt),
            'source_len' => mb_strlen($sourceText),
            'keys_available' => count($this->collectApiKeys()),
        ]);
          // "логируем параметры запроса"

        $response = $this->chatWithKeyRotation($payload, 'askOpenAi');
        //// "отправляем запрос в OpenAI (с возможностью смены API-ключа)"
        if ($response === null) {
            return null;
        }

        $this->logCompletionUsage($response);
        // // "логируем токены (стоимость запроса)"
        $this->logIfTruncated($response, 'askOpenAi');
        //  // "проверяем, не обрезан ли ответ"


        $content = $response->choices[0]->message->content ?? null;
        // // "достаём текст ответа из структуры OpenAI"
        if (! is_string($content)) {
            Log::error('[OpenAiService] askOpenAi: no text in response');

            return null;
        }
         // "если нет текста → ошибка"

        $content = trim($content);
          // "очищаем результат"

        return $content !== '' ? $content : null;
         // "возвращаем текст или null"
    }

    /**
     * @param  array<string, mixed>  $payload
     */

     // "выполняет запрос к OpenAI, переключая API-ключи при ошибках"
    private function chatWithKeyRotation(array $payload, string $step): mixed
    {
        $keys = $this->collectApiKeys();
         // "получаем список всех API-ключей"
        if ($keys === []) {
            Log::error('[OpenAiService] no OpenAI API keys configured (OPENAI_API_KEY / OPENAI_API_KEYS)');

            return null;
        }
 // "если ключей нет → сразу ошибка"
        foreach ($keys as $index => $apiKey) {
  // "перебираем каждый ключ по очереди"

            $client = OpenAI::client($apiKey);
 // "создаём клиента OpenAI с текущим ключом"

            Log::info('[OpenAiService] using key slot', [
                'index' => $index,
                'key_preview' => $this->maskKeyForLog($apiKey),
            ]);
 // "логируем, какой ключ используем (частично скрытый)"
            $outcome = $this->tryChatCompletionWithRetries($client, $payload, $step);
                // "пытаемся сделать запрос (с ретраями)"
            if ($outcome['response'] !== null) {
                return $outcome['response'];
            }
              // "если успешно → возвращаем ответ"
            if (! $outcome['try_next_key']) {
                return null;
            }
              // "если ошибка НЕ требует смены ключа → выходим"
            Log::warning('[OpenAiService] switching to next API key', [
                'step' => $step,
                'reason' => $outcome['reason'] ?? 'unknown',
                'failed_index' => $index,
            ]);
              // "если ключ умер → пробуем следующий"
        }

        Log::error('[OpenAiService] all API keys failed for this request', ['step' => $step]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{response: mixed, try_next_key: bool, reason?: string}
     */

// "повторяет запрос к OpenAI при ошибках (rate limit, временные сбои)
// и решает, нужно ли пробовать другой API-ключ"

    private function tryChatCompletionWithRetries(Client $client, array $payload, string $step): array
    {
        $maxAttempts = (int) config('services.openai.rate_limit_retries', 8);
        $baseWait = (int) config('services.openai.rate_limit_wait_base_sec', 10);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return ['response' => $client->chat()->create($payload), 'try_next_key' => false];
            } catch (RateLimitException $e) {
                $wait = $baseWait * $attempt;
                Log::warning('[OpenAiService] rate limit 429, waiting', [
                    'step' => $step,
                    'attempt' => $attempt,
                    'wait_sec' => $wait,
                ]);
                sleep($wait);
            } catch (ErrorException $e) {
                $code = $e->getStatusCode();
                $msg = $this->sanitizeLogMessage($e->getMessage());

                if (in_array($code, [401, 403], true)) {
                    Log::error('[OpenAiService] auth error, will try next key if any', [
                        'step' => $step,
                        'http' => $code,
                        'message' => $msg,
                    ]);

                    return ['response' => null, 'try_next_key' => true, 'reason' => 'http_'.$code];
                }

                if ($code === 429) {
                    $wait = $baseWait * $attempt;
                    Log::warning('[OpenAiService] HTTP 429, waiting', [
                        'step' => $step,
                        'attempt' => $attempt,
                        'wait_sec' => $wait,
                    ]);
                    sleep($wait);

                    continue;
                }

                Log::error('[OpenAiService] API error', [
                    'step' => $step,
                    'http' => $code,
                    'message' => $msg,
                ]);

                return ['response' => null, 'try_next_key' => false, 'reason' => 'http_'.$code];
            } catch (Throwable $e) {
                if ($e instanceof UnserializableResponse) {
                    $wait = min(2 * $attempt, 8);
                    Log::warning('[OpenAiService] unserializable response, retrying same request', [
                        'step' => $step,
                        'attempt' => $attempt,
                        'wait_sec' => $wait,
                        'exception' => $e::class,
                        'message' => $this->sanitizeLogMessage($e->getMessage()),
                    ]);

                    if ($attempt < $maxAttempts) {
                        sleep($wait);
                        continue;
                    }

                    Log::error('[OpenAiService] unserializable response retries exhausted', [
                        'step' => $step,
                        'attempts' => $maxAttempts,
                    ]);

                    return ['response' => null, 'try_next_key' => true, 'reason' => 'unserializable_response'];
                }

                Log::error('[OpenAiService] unexpected error', [
                    'step' => $step,
                    'message' => $this->sanitizeLogMessage($e->getMessage()),
                    'exception' => $e::class,
                ]);

                return ['response' => null, 'try_next_key' => false, 'reason' => 'exception'];
            }
        }

        Log::error('[OpenAiService] max retries exceeded (rate limit)', ['step' => $step]);

        return ['response' => null, 'try_next_key' => true, 'reason' => 'rate_limit_exhausted'];
    }

    /**
     * Конвейер AiFieldGeneratorJob: материал + инструкция этапа из БД.
     */
    public function chat(string $material, string $instruction): ?string
    {
        return $this->askOpenAi(trim($instruction), trim($material), 'openai.chat');
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
            Log::warning("[OpenAiService] reply truncated ({$stepLabel}); raise OPENAI_MAX_OUTPUT_TOKENS if needed.");
        }
    }
//маскирует API-ключ для безопасного логирования
    private function resolvedModel(): string
    {
        $model = trim((string) config('services.openai.model'));
        if ($model === '') {
            throw new RuntimeException('Задайте OPENAI_MODEL в .env (config/services.openai.model).');
        }

        return $model;
    }

    /**
     * GPT-5+ в Chat Completions принимает max_completion_tokens, не max_tokens.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyOutputTokenLimit(array &$payload, int $maxOut): string
    {
        $model = (string) ($payload['model'] ?? '');
        $key = preg_match('/^gpt-5/i', $model) === 1 ? 'max_completion_tokens' : 'max_tokens';
        $payload[$key] = $maxOut;

        return $key;
    }

    private function maskKeyForLog(string $apiKey): string
    {
        $t = trim($apiKey);
        if ($t === '') {
            return '(empty)';
        }
        if (strlen($t) <= 12) {
            return substr($t, 0, 4).'…';
        }

        return substr($t, 0, 7).'…'.substr($t, -4);
    }
//удаляет API-ключи из логов
    private function sanitizeLogMessage(string $message): string
    {
        $out = preg_replace('/sk-[a-zA-Z0-9_-]{8,}\S*/', 'sk-[REDACTED]', $message);

        return is_string($out) ? $out : $message;
    }

    /**
     * Что реально уходит в user-сообщение: инструкция этапа + разделитель + материал (сырьё / шаг пайплайна).
     */
    //логирует, что отправляется в OpenAI (без полного раскрытия данных)
    private function logPipelineMaterial(string $callSite, string $instruction, string $material): void
    {
        $preview = 900;
        Log::info('[OpenAiService] pipeline material (before API)', [
            'call' => $callSite,
            'instruction_len' => mb_strlen($instruction),
            'material_len' => mb_strlen($material),
            'material_sha1' => hash('sha1', $material),
            'instruction_preview' => mb_substr($instruction, 0, $preview),
            'material_preview' => mb_substr($material, 0, $preview),
        ]);
    }
}
