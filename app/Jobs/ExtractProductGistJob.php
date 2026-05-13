<?php

namespace App\Jobs;

use App\Models\ExtractionPrompt;
use App\Models\Product;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExtractProductGistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK_CHAR_LIMIT = 60000;

    public const WARN_SOURCE_CHARS = 240000;

    public const MAX_SOURCE_CHARS = 720000;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public Product $product,
        public string $sourceText = ''
    ) {}

    public function handle(): void
    {
        $product = $this->product->fresh();
        if (! $product) {
            throw new RuntimeException('Товар для выжимки не найден.');
        }

        $sourceMaterial = trim($this->sourceText !== '' ? $this->sourceText : (string) ($product->source_text ?? ''));
        if ($sourceMaterial === '') {
            throw new RuntimeException('Пустое исходное сырьё — выжимка невозможна.');
        }

        if (mb_strlen($sourceMaterial) > self::MAX_SOURCE_CHARS) {
            throw new RuntimeException(
                'Сырьё слишком большое: '.mb_strlen($sourceMaterial).' символов. Максимум: '.self::MAX_SOURCE_CHARS.' символов.'
            );
        }

        $sourceSha1 = hash('sha1', $sourceMaterial);
        $currentResult = trim((string) ($product->result ?? ''));
        $currentSha1 = (string) ($product->result_source_sha1 ?? '');

        Cache::put($this->startedCacheKey($product->id), time(), 86400);
        Cache::forget($this->errorCacheKey($product->id));

        if ($currentResult !== '' && hash_equals($currentSha1, $sourceSha1)) {
            Cache::forget($this->startedCacheKey($product->id));
            Log::info('[ExtractProductGistJob] Выжимка уже актуальна, OpenAI не вызываем', [
                'product_id' => $product->id,
                'source_len' => mb_strlen($sourceMaterial),
                'result_len' => mb_strlen($currentResult),
                'source_sha1' => $sourceSha1,
            ]);

            return;
        }

        $prompt = ExtractionPrompt::active();
        if (! $prompt || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException('Активный промпт для выжимки не найден в extraction_prompts.');
        }

        Log::info('[ExtractProductGistJob] Старт выжимки сырья', [
            'product_id' => $product->id,
            'source_len' => mb_strlen($sourceMaterial),
            'source_sha1' => $sourceSha1,
            'prompt_id' => $prompt->id,
            'prompt_key' => $prompt->key,
        ]);

        $gist = $this->buildGist(app(OpenAiService::class), (string) $prompt->prompt_text, $sourceMaterial, $product->id);

        if ($gist === '') {
            throw new RuntimeException('OpenAI вернул пустую выжимку.');
        }

        $product->forceFill([
            'source_text' => $sourceMaterial,
            'result' => $gist,
            'result_source_sha1' => $sourceSha1,
        ])->save();

        Cache::forget($this->startedCacheKey($product->id));
        Cache::forget($this->errorCacheKey($product->id));

        Log::info('[ExtractProductGistJob] Выжимка сработала и записана в products.result', [
            'product_id' => $product->id,
            'source_len' => mb_strlen($sourceMaterial),
            'gist_len' => mb_strlen($gist),
            'source_sha1' => $sourceSha1,
        ]);
    }

    private function buildGist(OpenAiService $openAi, string $promptText, string $sourceMaterial, int $productId): string
    {
        $chunks = $this->splitTextIntoChunks($sourceMaterial);

        if (count($chunks) === 1) {
            $result = $openAi->chat($sourceMaterial, $promptText.$this->singlePassInstruction());

            return trim((string) ($result ?? ''));
        }

        Log::info('[ExtractProductGistJob] Сырьё разбито на части', [
            'product_id' => $productId,
            'chunks_count' => count($chunks),
            'chunk_limit' => self::CHUNK_CHAR_LIMIT,
            'source_len' => mb_strlen($sourceMaterial),
        ]);

        $partials = [];
        $total = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $part = $index + 1;
            Log::info('[ExtractProductGistJob] Выжимка части', [
                'product_id' => $productId,
                'part' => $part,
                'total' => $total,
                'chunk_len' => mb_strlen($chunk),
            ]);

            $partial = $openAi->chat($chunk, $promptText.$this->chunkInstruction($part, $total));
            $partialText = trim((string) ($partial ?? ''));
            if ($partialText === '') {
                throw new RuntimeException('OpenAI вернул пустую выжимку для части '.$part.' из '.$total.'.');
            }

            $partials[] = "## Часть {$part} из {$total}\n".$partialText;
        }

        $partialsText = implode("\n\n---\n\n", $partials);
        Log::info('[ExtractProductGistJob] Финальная сборка выжимки', [
            'product_id' => $productId,
            'partials_len' => mb_strlen($partialsText),
            'chunks_count' => $total,
        ]);

        $final = $openAi->chat($partialsText, $promptText.$this->mergeInstruction($total));

        return trim((string) ($final ?? ''));
    }

    /**
     * @return list<string>
     */
    private function splitTextIntoChunks(string $text): array
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $normalized = preg_replace("/[ \t]+/", ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
        $paragraphs = preg_split("/\n{2,}/", trim($normalized)) ?: [];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > self::CHUNK_CHAR_LIMIT) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->splitLongText($paragraph) as $part) {
                    $chunks[] = $part;
                }
                continue;
            }

            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            if (mb_strlen($candidate) > self::CHUNK_CHAR_LIMIT) {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [trim($normalized)] : $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitLongText(string $text): array
    {
        $parts = [];
        $remaining = trim($text);

        while (mb_strlen($remaining) > self::CHUNK_CHAR_LIMIT) {
            $slice = mb_substr($remaining, 0, self::CHUNK_CHAR_LIMIT);
            $breakAt = max(
                (int) mb_strrpos($slice, '. '),
                (int) mb_strrpos($slice, '! '),
                (int) mb_strrpos($slice, '? '),
                (int) mb_strrpos($slice, "\n")
            );

            if ($breakAt < (int) (self::CHUNK_CHAR_LIMIT * 0.6)) {
                $breakAt = self::CHUNK_CHAR_LIMIT;
            }

            $parts[] = trim(mb_substr($remaining, 0, $breakAt));
            $remaining = trim(mb_substr($remaining, $breakAt));
        }

        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }

    private function singlePassInstruction(): string
    {
        return "\n\nДополнительное ограничение: сделай финальную выжимку компактной, цель 30000-60000 символов, максимум 80000 символов. Убери повторы, но не теряй факты.";
    }

    private function chunkInstruction(int $part, int $total): string
    {
        return "\n\nЭто часть {$part} из {$total} большого документа. Сделай промежуточную выжимку только по этой части. Не делай общий вывод по всему документу. Цель: до 8000-10000 символов для этой части.";
    }

    private function mergeInstruction(int $total): string
    {
        return "\n\nПеред тобой {$total} промежуточных выжимок из одного документа. Собери одну финальную выжимку для дальнейшей генерации статей. Убери дубли между частями, сохрани все важные факты, имена, цифры, цены, маршруты и расписания. Цель финального текста: 30000-60000 символов, максимум 80000 символов.";
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget($this->startedCacheKey($this->product->id));
        Cache::put(
            $this->errorCacheKey($this->product->id),
            [
                'at' => time(),
                'message' => $exception?->getMessage(),
                'exception_class' => $exception ? $exception::class : null,
            ],
            now()->addDay()
        );

        Log::error('[ExtractProductGistJob] Выжимка остановлена (failed)', [
            'product_id' => $this->product->id,
            'message' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }

    private function startedCacheKey(int $productId): string
    {
        return 'product_ai_extraction_started_at:'.$productId;
    }

    private function errorCacheKey(int $productId): string
    {
        return 'product_ai_extraction_error:'.$productId;
    }
}
