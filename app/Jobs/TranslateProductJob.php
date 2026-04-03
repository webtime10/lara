<?php

namespace App\Jobs;

use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Exceptions\RateLimitException;

/**
 * Фоновая задача: берёт базовый текст (обычно products.result), строит JSON-переводы
 * по языкам ru/en/he/ar и пишет в выбранное ai_* поле таблицы product_descriptions.
 */
class TranslateProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Product $product;

    public string $sourceText;

    /** Например: ai_text_about_the_country или ai_reviews_from_tourists. */
    public string $targetField;

    public $timeout = 600;

    /** Повтор job при лимитах OpenAI или сбоях сети (вся генерация заново). */
    public int $tries = 3;

    /** Секунды между попытками всего job. */
    public array $backoff = [90, 180, 300];

    public function __construct(Product $product, string $sourceText, string $targetField)
    {
        $this->product = $product;
        $this->sourceText = $sourceText;
        $this->targetField = $targetField;
    }

    public function handle(): void
    {
        $productId = $this->product->id;
        $runId = substr((string) microtime(true), -8);

        Log::info('[TranslateProductJob] Старт', [
            'run_id' => $runId,
            'product_id' => $productId,
            'target_field' => $this->targetField,
            'source_len' => mb_strlen($this->sourceText),
        ]);

        try {
            $service = app(OpenAiService::class);
            $translated = $service->generateTranslationsForField($this->sourceText, $this->targetField);
        } catch (RateLimitException $e) {
            $sec = (int) $e->response->getHeaderLine('Retry-After');
            if ($sec < 15) {
                $sec = (int) env('OPENAI_JOB_RELEASE_SEC', 90);
            }
            $sec = max(15, min(600, $sec));

            Log::warning('[TranslateProductJob] OpenAI 429, откладываю задачу', [
                'run_id' => $runId,
                'product_id' => $productId,
                'release_in_sec' => $sec,
            ]);
            $this->release($sec);

            return;
        }

        if ($translated === null) {
            Log::warning('[TranslateProductJob] Пустой или невалидный ответ AI', [
                'run_id' => $runId,
                'product_id' => $productId,
                'target_field' => $this->targetField,
            ]);

            return;
        }

        // Сохраняем буфер результата в products.result, чтобы текст был виден в textarea "Result".
        $resultBuffer = $translated['ru'] ?? reset($translated);
        if (is_string($resultBuffer) && trim($resultBuffer) !== '') {
            Product::query()->whereKey($productId)->update([
                'result' => $resultBuffer,
            ]);
            Log::info('[TranslateProductJob] Обновлён products.result', [
                'run_id' => $runId,
                'product_id' => $productId,
                'result_len' => mb_strlen($resultBuffer),
            ]);
        }

        Log::info('[TranslateProductJob] Получен ответ от сервиса', [
            'run_id' => $runId,
            'languages' => implode(',', array_keys($translated)),
        ]);

        $languages = Language::all();
        $updated = [];
        $skipped = [];
        foreach ($languages as $language) {
            $existing = ProductDescription::query()
                ->where('product_id', $productId)
                ->where('language_id', $language->id)
                ->first();

            $slug = $existing?->slug;
            if (! $slug) {
                $slug = Str::slug($this->product->model.'-'.$language->code);
            }

            $textForLang = $translated[$language->code] ?? null;
            if (! is_string($textForLang) || trim($textForLang) === '') {
                $skipped[] = $language->code;
                continue;
            }

            ProductDescription::updateOrCreate(
                [
                    'product_id' => $productId,
                    'language_id' => $language->id,
                ],
                [
                    'name' => $existing?->name ?? $this->product->model,
                    'slug' => $slug,
                    $this->targetField => $textForLang,
                ]
            );
            $updated[] = $language->code;
            Log::info('[TranslateProductJob] Язык обновлен', [
                'run_id' => $runId,
                'product_id' => $productId,
                'lang' => $language->code,
                'payload_len' => mb_strlen($textForLang),
            ]);
        }

        Log::info('[TranslateProductJob] Готово', [
            'run_id' => $runId,
            'product_id' => $productId,
            'target_field' => $this->targetField,
            'languages_count' => count($translated),
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }
}
