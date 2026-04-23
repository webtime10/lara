<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Один запрос к Gemini API: инструкция этапа + материал (как в OpenAiService::askOpenAi).
 */
class GeminiService
{
    public function chat(string $material, string $instruction): ?string
    {
        $instruction = trim($instruction);
        $material = trim($material);

        if ($instruction === '' || $material === '') {
            Log::warning('[GeminiService] chat: пустая инструкция или материал', [
                'instruction_len' => mb_strlen($instruction),
                'material_len' => mb_strlen($material),
            ]);

            return null;
        }

        $preview = 900;
        Log::info('[GeminiService] pipeline material (before API)', [
            'call' => 'chat',
            'instruction_len' => mb_strlen($instruction),
            'material_len' => mb_strlen($material),
            'material_sha1' => hash('sha1', $material),
            'instruction_preview' => mb_substr($instruction, 0, $preview),
            'material_preview' => mb_substr($material, 0, $preview),
        ]);

        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            Log::error('[GeminiService] chat: не задан GEMINI_API_KEY');

            return null;
        }

        $model = trim((string) config('services.gemini.model', 'gemini-2.5-flash'));
        if ($model === '') {
            Log::error('[GeminiService] chat: пустой GEMINI_MODEL');

            return null;
        }

        $userContent = $instruction."\n\n--- SOURCE TEXT ---\n".$material;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            .rawurlencode($model)
            .':generateContent?key='.$apiKey;

        Log::info('[GeminiService] chat: HTTP request', [
            'model' => $model,
            'instruction_len' => mb_strlen($instruction),
            'material_len' => mb_strlen($material),
        ]);

        try {
            $response = Http::timeout(180)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => $userContent]],
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::error('[GeminiService] chat: сеть/HTTP исключение', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('[GeminiService] chat: неуспешный ответ API', [
                'status' => $response->status(),
                'body' => $this->truncateForLog($response->body()),
            ]);

            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($text)) {
            Log::error('[GeminiService] chat: в JSON нет текста ответа', [
                'json_keys' => array_keys($response->json() ?? []),
            ]);

            return null;
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function resolveApiKey(): string
    {
        $raw = (string) config('services.gemini.key', '');
        $raw = trim($raw, " \t\n\r\0\x0B\"'");
        if ($raw === '') {
            return '';
        }

        $keys = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($keys === []) {
            return '';
        }

        return (string) $keys[array_rand($keys)];
    }

    private function truncateForLog(string $body, int $max = 4000): string
    {
        if (mb_strlen($body) <= $max) {
            return $body;
        }

        return mb_substr($body, 0, $max).'…';
    }
}
