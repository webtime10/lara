<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\WpPublishJob;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WordPressController extends Controller
{
    public function publish(Product $product): JsonResponse
    {
        $hasData = DB::table('product_descriptions')->where('product_id', $product->id)->exists();
//  ->exists(); проверка есть ли чтот в базе данных
        if (! $hasData) {
            return response()->json(['message' => 'No data to publish.'], 400);
        }

        try {
            // Для кнопки "Опубликовать в WordPress" выполняем сразу,
            // чтобы не зависеть от состояния queue worker (pcntl и т.п.).
            WpPublishJob::dispatchSync($product);

            return response()->json([
                'status' => 'sent',
                'message' => 'Published to WordPress',
            ]);
        } catch (\Throwable $e) {
            Log::error('[WordPressController] Publish failed', [
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Publish failed: '.$e->getMessage(),
            ], 500);
        }

        // проверяю есть ли что-то в базе данных отправляю в очередь (воркер) выдаю сообщение 
        //  'status' => 'queued',
        // 'message' => 'Publish queued', успешно в очереди

    }
}
