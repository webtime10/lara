<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductDescription;
use App\Services\GeminiService;
use App\Services\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiFieldGeneratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ECHO_PREFIX_LEN = 320;

    private const ECHO_SIMILARITY_THRESHOLD = 91.0;

    /** Должен покрывать 3×API на длинных текстах; см. config ai.generation.timeout_seconds для опроса UI. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public Product $product,
        public int $languageId,
        public string $targetField,
        public string $sourceText,
        /** @var object Результат DB::selectOne по prompt_category_descriptions + join */
        public object $prompts
    ) {}

    public function handle(): void
    {
        $ctx = $this->logContext();
        $this->assertAllowedTargetField();

        // Этап 1: сырьё — сначала текст этого запуска (generateAi уже собрал Source/Result/БД с правильным приоритетом),
        // иначе product_descriptions.result для языка (очередь без устаревшего несохранённого текста не затирает свежий ввод).
        $sourceMaterial = trim($this->sourceText);
        if ($sourceMaterial === '') {
            $rowResult = DB::table('product_descriptions')
                ->where('product_id', $this->product->id)
                ->where('language_id', $this->languageId)
                ->value('result');
            $sourceMaterial = trim(is_string($rowResult) ? $rowResult : (string) ($rowResult ?? ''));
        }
        if ($sourceMaterial === '') {
            Log::error('[AiFieldGeneratorJob] Пустое сырьё в джобе', $ctx);
            throw new RuntimeException('Пустое сырьё (sourceText из запуска или product_descriptions.result) — генерация невозможна.');
        }

        Log::info('[AiFieldGeneratorJob] Старт: сырьё → колонка (этап1) → та же колонка (этап2) → та же колонка (этап3)', $ctx + [
            'source_len' => mb_strlen($sourceMaterial),
            'source_sha1' => hash('sha1', $sourceMaterial),
        ]);

        $instruction1 = trim((string) ($this->prompts->description ?? ''));
        $instruction2 = trim((string) ($this->prompts->stage_2_live ?? ''));
        $instruction3 = trim((string) ($this->prompts->stage_3_edit ?? ''));

        if ($instruction1 === '' || $instruction2 === '' || $instruction3 === '') {
            Log::error('[AiFieldGeneratorJob] В БД неполный набор промптов для трёх этапов', $ctx + [
                'has_description' => $instruction1 !== '',
                'has_stage_2_live' => $instruction2 !== '',
                'has_stage_3_edit' => $instruction3 !== '',
            ]);
            throw new RuntimeException('Заполните description, stage_2_live и stage_3_edit для категории промта (язык '.$this->languageId.').');
        }

        $this->updateStatus(2);

        $openAi = app(OpenAiService::class);
        $gemini = app(GeminiService::class);

        // --- Этап 1: сырьё из джобы → API → та же колонка ---
        Log::info('[AiFieldGeneratorJob] Этап 1: OpenAI (Extraction) → запись в колонку', $ctx + [
            'instruction_len' => mb_strlen($instruction1),
            'material_len' => mb_strlen($sourceMaterial),
        ]);

        $this->assertMaterialDiffersFromInstruction('before_extraction', $sourceMaterial, $instruction1, $ctx);

        $step1 = $this->assertPipelineStage(
            'OpenAI Extraction',
            $openAi->chat($sourceMaterial, $instruction1),
            $instruction1,
            $ctx
        );
        $this->persistStageToTargetColumn($step1, $ctx, 'after_extraction');

        // --- Этап 2: материал из той же колонки (результат этапа 1) ---
        $materialForStage2 = $this->loadMaterialFromTargetColumn($ctx, 'before_enliven');
        $this->assertMaterialDiffersFromInstruction('before_enliven', $materialForStage2, $instruction2, $ctx);
        Log::info('[AiFieldGeneratorJob] Этап 2: OpenAI (Enliven), материал из БД', $ctx + [
            'instruction_len' => mb_strlen($instruction2),
            'material_len' => mb_strlen($materialForStage2),
        ]);

        $step2 = $this->assertPipelineStage(
            'OpenAI Enliven',
            $openAi->chat($materialForStage2, $instruction2),
            $instruction2,
            $ctx
        );
        $this->persistStageToTargetColumn($step2, $ctx, 'after_enliven');

        // --- Этап 3: снова из колонки → Gemini → та же колонка (финал для вида) ---
        $materialForStage3 = $this->loadMaterialFromTargetColumn($ctx, 'before_editing');
        $this->assertMaterialDiffersFromInstruction('before_editing', $materialForStage3, $instruction3, $ctx);
        Log::info('[AiFieldGeneratorJob] Этап 3: Gemini (Editing), материал из БД', $ctx + [
            'instruction_len' => mb_strlen($instruction3),
            'material_len' => mb_strlen($materialForStage3),
        ]);

        $finalResult = $this->assertPipelineStage(
            'Gemini Editing',
            $gemini->chat($materialForStage3, $instruction3),
            $instruction3,
            $ctx
        );
        $this->persistStageToTargetColumn($finalResult, $ctx, 'after_editing_final');

        $this->updateStatus(4);
        $this->clearCache();

        Log::info('[AiFieldGeneratorJob] Конвейер успешно завершён (все этапы в одной колонке)', $ctx);
    }

    private function assertAllowedTargetField(): void
    {
        if (! in_array($this->targetField, ProductDescription::aiFieldKeys(), true)) {
            throw new RuntimeException('Недопустимое целевое поле: '.$this->targetField);
        }
    }

    /**
     * Сохраняет результат этапа в ту же колонку product_descriptions.{target_field} — вид/фронт могут опросить БД и увидеть прогресс.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function persistStageToTargetColumn(string $text, array $ctx, string $stageTag): void
    {
        $updated = DB::table('product_descriptions')
            ->where('product_id', $this->product->id)
            ->where('language_id', $this->languageId)
            ->update([$this->targetField => $text]);

        if ($updated === 0) {
            Log::error('[AiFieldGeneratorJob] Не удалось записать этап в колонку', $ctx + ['stage' => $stageTag]);
            throw new RuntimeException('Строка product_descriptions не найдена или колонка не обновлена (этап: '.$stageTag.').');
        }

        Log::info('[AiFieldGeneratorJob] Результат этапа записан в колонку', $ctx + [
            'stage' => $stageTag,
            'written_len' => mb_strlen($text),
        ]);
    }

    /**
     * Читает текущее содержимое целевой колонки — вход для следующего промпта.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function loadMaterialFromTargetColumn(array $ctx, string $readTag): string
    {
        $raw = DB::table('product_descriptions')
            ->where('product_id', $this->product->id)
            ->where('language_id', $this->languageId)
            ->value($this->targetField);

        $text = is_string($raw) ? trim($raw) : trim((string) ($raw ?? ''));

        if ($text === '') {
            Log::error('[AiFieldGeneratorJob] Колонка пуста при чтении для следующего этапа', $ctx + ['read_tag' => $readTag]);
            throw new RuntimeException('Пустая колонка '.$this->targetField.' при чтении материала ('.$readTag.').');
        }

        Log::info('[AiFieldGeneratorJob] Материал для следующего этапа прочитан из колонки', $ctx + [
            'read_tag' => $readTag,
            'loaded_len' => mb_strlen($text),
            'loaded_sha1' => hash('sha1', $text),
        ]);

        return $text;
    }

    /**
     * Если в БД подтянулась «не та» строка промптов, материал может совпасть с инструкцией — тогда API часто возвращает сам промпт.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function assertMaterialDiffersFromInstruction(string $readTag, string $material, string $instruction, array $ctx): void
    {
        $m = trim($material);
        $i = trim($instruction);
        if ($m === '' || $i === '') {
            return;
        }
        if (mb_strtolower($m) === mb_strtolower($i)) {
            Log::error('[AiFieldGeneratorJob] Материал для API совпадает с инструкцией этапа (проверьте выбор строки prompt_categories / manufacturer)', $ctx + [
                'read_tag' => $readTag,
                'len' => mb_strlen($m),
            ]);
            throw new RuntimeException('Материал совпадает с текстом инструкции ('.$readTag.') — вероятно выбрана неверная категория промта в БД.');
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function assertPipelineStage(string $stageLabel, ?string $raw, string $instruction, array $ctx): string
    {
        if ($raw === null) {
            Log::error('[AiFieldGeneratorJob] API вернул null', $ctx + ['stage' => $stageLabel]);
            throw new RuntimeException("[{$stageLabel}] Пустой ответ API (null).");
        }

        $out = trim($raw);
        if ($out === '') {
            Log::error('[AiFieldGeneratorJob] API вернул пустую строку', $ctx + ['stage' => $stageLabel]);
            throw new RuntimeException("[{$stageLabel}] Пустой ответ после trim.");
        }

        if ($this->outputEchoesInstruction($out, $instruction)) {
            Log::error('[AiFieldGeneratorJob] Ответ похож на текст инструкции (echo промпта)', $ctx + [
                'stage' => $stageLabel,
                'output_len' => mb_strlen($out),
                'instruction_len' => mb_strlen(trim($instruction)),
                'output_preview' => mb_substr($out, 0, 500),
                'instruction_preview' => mb_substr(trim($instruction), 0, 500),
            ]);
            throw new RuntimeException(
                "[{$stageLabel}] Ответ совпадает с инструкцией или её фрагментом — вероятно в запрос не попало сырьё или модель вернула промпт."
            );
        }

        return $out;
    }

    private function outputEchoesInstruction(string $output, string $instruction): bool
    {
        $o = trim($output);
        $i = trim($instruction);
        if ($o === '' || $i === '') {
            return false;
        }

        if (mb_strtolower($o) === mb_strtolower($i)) {
            return true;
        }

        $lenO = mb_strlen($o);
        $lenI = mb_strlen($i);

        // Короткая инструкция (часто stage_3): ответ «промптом» — почти весь вывод совпадает с началом инструкции.
        if ($lenI >= 40 && $lenI <= 800 && $lenO >= $lenI && mb_stripos($o, $i) === 0 && $lenO <= (int) ($lenI * 1.25)) {
            return true;
        }

        $prefixLen = (int) min(self::ECHO_PREFIX_LEN, $lenO, $lenI);
        if ($prefixLen >= 120 && mb_substr($o, 0, $prefixLen) === mb_substr($i, 0, $prefixLen) && $lenO <= (int) ($lenI * 1.25)) {
            return true;
        }

        if ($lenO >= 200 && $lenO <= $lenI && mb_strpos($i, $o) !== false) {
            return true;
        }

        if ($lenI >= 200 && $lenO >= $lenI && mb_stripos($o, $i) !== false && $lenO <= (int) ($lenI * 1.15)) {
            return true;
        }

        $cap = 3500;
        $so = $lenO > $cap ? mb_substr($o, 0, $cap) : $o;
        $si = $lenI > $cap ? mb_substr($i, 0, $cap) : $i;
        $percent = 0.0;
        similar_text(mb_strtolower($so), mb_strtolower($si), $percent);
        if ($percent >= self::ECHO_SIMILARITY_THRESHOLD && $lenO <= (int) ($lenI * 1.2)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function logContext(): array
    {
        return [
            'product_id' => $this->product->id,
            'manufacturer_id' => $this->product->manufacturer_id,
            'language_id' => $this->languageId,
            'target_field' => $this->targetField,
            'source_len' => mb_strlen($this->sourceText),
        ];
    }

    protected function updateStatus(int $status): void
    {
        DB::table('products')
            ->where('id', $this->product->id)
            ->update(['ai_status' => json_encode($status)]);
    }

    /**
     * Ключи должны совпадать с ProductController::aiGenerationStartedCacheKey / aiGenerationErrorCacheKey.
     */
    protected function clearCache(): void
    {
        Cache::forget('product_ai_generation_started_at:'.$this->product->id.':'.$this->targetField);
        Cache::forget('product_ai_generation_error:'.$this->product->id.':'.$this->targetField);
    }

    public function failed(?Throwable $exception): void
    {
        $this->updateStatus(5);
        $this->clearCache();

        Log::error('[AiFieldGeneratorJob] Конвейер остановлен (failed)', $this->logContext() + [
            'message' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
