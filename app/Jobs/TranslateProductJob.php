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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Exceptions\RateLimitException;

class TranslateProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Product $product;
    public string $sourceText;
    public string $targetField;

    // Даем воркеру 10 минут на раздумья, ИИ парень не быстрый
    public $timeout = 600;

    // Если всё упало, пробуем еще 3 раза. Настырность — наше всё.
    public int $tries = 3;

    // Если OpenAI капризничает, возвращаемся через 1.5, 3 или 5 минут
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
        // Уникальный маячок для логов, чтобы не запутаться в пачке запусков
        $runId = substr((string) microtime(true), -8);

        Log::info('[TranslateProductJob] Погнали!', [
            'run_id' => $runId,
            'product_id' => $productId,
            'target_field' => $this->targetField,
        ]);

        try {
            $service = app(OpenAiService::class);
            // Отдаем текст "мастеру" на генерацию и ждем готовую пачку статей
            $generatedContent = $service->generateTranslationsForField($this->sourceText, $this->targetField);
        } catch (RateLimitException $e) {
            // Если у OpenAI очередь на входе, вежливо отступаем и пробуем позже
            $sec = (int) $e->response->getHeaderLine('Retry-After');
            $sec = max(15, min(600, $sec ?: 90));

            Log::warning('[TranslateProductJob] OpenAI перегружен, ждем...', ['run_id' => $runId, 'sec' => $sec]);
            $this->release($sec);
            return;
        }

        if ($generatedContent === null) {
            Log::warning('[TranslateProductJob] ИИ выдал пустоту. Отмена.', ['run_id' => $runId]);
            $this->markGenerationFailed('ИИ не вернул данные (OpenAI/Gemini или пустой ответ).');

            return;
        }

        // Обновляем "витрину" (главное поле товара) — берем русский текст как эталон
        $resultBuffer = $generatedContent['ru'] ?? reset($generatedContent);
        if (is_string($resultBuffer) && trim($resultBuffer) !== '') {
            Product::query()->whereKey($productId)->update(['result' => $resultBuffer]);
            Log::info('[TranslateProductJob] Главное поле Result заполнено', ['run_id' => $runId]);
        }

        $languages = Language::all();
        $updated = [];
        $skipped = [];

        // Теперь раскладываем готовую работу по всем языковым папкам
        foreach ($languages as $language) {
            $existing = ProductDescription::query()
                ->where('product_id', $productId)
                ->where('language_id', $language->id)
                ->first();

            // Если у страницы еще нет адреса (slug) — создаем его на лету
            $slug = $existing?->slug ?? Str::slug($this->product->model . '-' . $language->code);

            // Ищем в ответе ИИ текст именно для этого языка
            $textForLang = $generatedContent[$language->code] ?? null;

            if (!$textForLang) {
                $skipped[] = $language->code;
                continue;
            }

            // Умная запись: если перевод уже был — обновим, если нет — создадим с нуля
            ProductDescription::updateOrCreate(
                ['product_id' => $productId, 'language_id' => $language->id],
                [
                    'name' => $existing?->name ?? $this->product->model,
                    'slug' => $slug,
                    $this->targetField => $textForLang,
                ]
            );
            $updated[] = $language->code;
        }

        $expectedCodes = array_map('strtolower', config('ai.generation.expected_languages', ['ru', 'en', 'he', 'ar']));
        $skippedCritical = array_values(array_intersect(array_map('strtolower', $skipped), $expectedCodes));

        if ($skippedCritical !== []) {
            $msg = 'Не удалось сгенерировать языки: '.implode(', ', $skippedCritical).'. Проверьте ключи API (Gemini/OpenAI) и laravel.log.';
            Log::warning('[TranslateProductJob] Неполный результат по обязательным языкам', [
                'run_id' => $runId,
                'skipped_critical' => $skippedCritical,
                'skipped_all' => $skipped,
                'updated' => $updated,
            ]);
            $this->markGenerationFailed($msg);

            return;
        }

        Log::info('[TranslateProductJob] Финиш! Всё по полкам.', [
            'run_id' => $runId,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    public function failed(?\Throwable $exception = null): void
    {
        $this->markGenerationFailed($exception !== null ? $exception->getMessage() : 'Задача очереди завершилась с ошибкой.');
    }

    private function markGenerationFailed(string $detail): void
    {
        $key = 'product_ai_generation_error:'.$this->product->id.':'.$this->targetField;
        Cache::put($key, $detail, 86400);
    }
}