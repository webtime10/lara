<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DispatchAiFieldGenerationJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  list<array{language_id:int,target_field:string,prompts:array<string,mixed>}>  $jobs
     */
    public function __construct(
        public Product $product,
        public array $jobs
    ) {}

    public function handle(): void
    {
        $product = $this->product->fresh();
        if (! $product) {
            throw new RuntimeException('Товар для запуска AI-задач не найден.');
        }

        $gist = trim((string) ($product->result ?? ''));
        if ($gist === '') {
            throw new RuntimeException('Выжимка products.result пуста — AI-задачи не запущены.');
        }

        foreach ($this->jobs as $job) {
            dispatch(new AiFieldGeneratorJob(
                $product,
                (int) $job['language_id'],
                (string) $job['target_field'],
                '',
                (object) $job['prompts']
            ));
        }

        Log::info('[DispatchAiFieldGenerationJobs] AI-задачи запущены после выжимки', [
            'product_id' => $product->id,
            'jobs_count' => count($this->jobs),
            'gist_len' => mb_strlen($gist),
        ]);
    }
}
