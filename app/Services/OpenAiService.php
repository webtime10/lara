<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
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
    public function getRandomKey(): string
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
        $seen = [];
        $out = [];
        $push = function (string $k) use (&$seen, &$out): void {
            $k = trim($k, " \t\n\r\0\x0B\"'");
            if ($k === '' || isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $out[] = $k;
        };

        $push((string) config('services.openai.key', ''));
        $csv = (string) config('services.openai.keys_csv', '');
        foreach (explode(',', $csv) as $part) {
            $push(trim($part));
        }

        return $out;
    }

    public function askOpenAi(string $prompt, string $sourceText, string $logCallSite = 'askOpenAi'): ?string
    {
        $prompt = trim($prompt);
        $sourceText = trim($sourceText);

        if ($prompt === '' || $sourceText === '') {
            Log::warning('[OpenAiService] askOpenAi: empty prompt or source text');

            return null;
        }

        $this->logPipelineMaterial($logCallSite, $prompt, $sourceText);

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $maxOut = (int) config('services.openai.max_output_tokens', 16384);
        $userContent = $prompt."\n\n--- SOURCE TEXT ---\n".$sourceText;

        $payload = [
            'model' => $model,
            'max_tokens' => $maxOut,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Следуй инструкциям пользователя точно. Возвращай только запрошенный результат, без пояснений «как модель», если явно не просят обратное.',
                ],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        Log::info('[OpenAiService] askOpenAi request', [
            'model' => $model,
            'max_tokens' => $maxOut,
            'prompt_len' => mb_strlen($prompt),
            'source_len' => mb_strlen($sourceText),
            'keys_available' => count($this->collectApiKeys()),
        ]);

        $response = $this->chatWithKeyRotation($payload, 'askOpenAi');
        if ($response === null) {
            return null;
        }

        $this->logCompletionUsage($response);
        $this->logIfTruncated($response, 'askOpenAi');

        $content = $response->choices[0]->message->content ?? null;
        if (! is_string($content)) {
            Log::error('[OpenAiService] askOpenAi: no text in response');

            return null;
        }

        $content = trim($content);

        return $content !== '' ? $content : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chatWithKeyRotation(array $payload, string $step): mixed
    {
        $keys = $this->collectApiKeys();
        if ($keys === []) {
            Log::error('[OpenAiService] no OpenAI API keys configured (OPENAI_API_KEY / OPENAI_API_KEYS)');

            return null;
        }

        foreach ($keys as $index => $apiKey) {
            $client = OpenAI::client($apiKey);
            Log::info('[OpenAiService] using key slot', [
                'index' => $index,
                'key_preview' => $this->maskKeyForLog($apiKey),
            ]);

            $outcome = $this->tryChatCompletionWithRetries($client, $payload, $step);
            if ($outcome['response'] !== null) {
                return $outcome['response'];
            }
            if (! $outcome['try_next_key']) {
                return null;
            }
            Log::warning('[OpenAiService] switching to next API key', [
                'step' => $step,
                'reason' => $outcome['reason'] ?? 'unknown',
                'failed_index' => $index,
            ]);
        }

        Log::error('[OpenAiService] all API keys failed for this request', ['step' => $step]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{response: mixed, try_next_key: bool, reason?: string}
     */
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

    private function sanitizeLogMessage(string $message): string
    {
        $out = preg_replace('/sk-[a-zA-Z0-9_-]{8,}\S*/', 'sk-[REDACTED]', $message);

        return is_string($out) ? $out : $message;
    }

    /**
     * Что реально уходит в user-сообщение: инструкция этапа + разделитель + материал (сырьё / шаг пайплайна).
     */
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
