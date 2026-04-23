<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Product;
use App\Models\ProductDescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка данных товара в WordPress (REST API).
 *
 * Общий секрет (API key):
 * - В Laravel: config('services.wordpress.webhook_secret') ← из .env WORDPRESS_WEBHOOK_SECRET
 * - Уходит в заголовке HTTP: X-Laravel-Api-Key (см. publish())
 * - В WordPress: константа LARAVEL_API_KEY в wp-config.php должна совпадать побайтно
 *
 * Это не OAuth и не сессия пользователя WP — это простой «общий пароль» между двумя вашими приложениями.
 */
class WordPressService
{
    /**
     * Собирает данные для WP: по **каждому активному языку** из таблицы `languages` — отдельный ключ в `ai_data`,
     * даже если для этого языка нет строки в `product_descriptions` или AI-поля пустые (тогда пустой массив).
     * Значения в БД могут быть JSON-строкой или обёрнуты в ```json ... ```.
     *
     * @return array{product_id:int, manufacturer_name:string, titles:array<string, string>, slugs:array<string, string>, ai_data:array<string, array<string, mixed>>}
     */





    public function prepareData(Product $product): array   //SELECT * FROM products WHERE id = 15 LIMIT 1;  в объекте эти данные
    {
        $product->loadMissing(['descriptions.language']);  
        // я подгрузил данные, и они добавились внутрь объекта  это набираем как массив только это объект
        // SELECT * FROM product_descriptions WHERE product_id = 15; 
        // SELECT * FROM languages WHERE id IN (1, 2, 3);
 /*
$product
   ├── id: 15
   ├── name: "iPhone"
   ├── manufacturer_id: 3

   ├── descriptions   ← ДОБАВИЛОСЬ
   │       ├── [0]
   │       │     ├── id: 1
   │       │     ├── product_id: 15
   │       │     ├── language_id: 1
   │       │     ├── name: "iPhone"
   │       │     └── language   ← ДОБАВИЛОСЬ
   │       │            ├── id: 1
   │       │            └── code: "en"
   │
   │       ├── [1]
   │       │     ├── id: 2
   │       │     ├── product_id: 15
   │       │     ├── language_id: 2
   │       │     ├── name: "Айфон"
   │       │     └── language
   │       │            ├── id: 2
   │       │            └── code: "ru"

   */
  
   $manufacturer = DB::table('manufacturers')->where('id', $product->manufacturer_id)->first(); // id мануфактурры

        if (! $manufacturer) {
            throw new \Exception("Производитель для товара ID {$product->id} не найден.");
        }

        $languages = Language::getActive(); // все языки
        if ($languages->isEmpty()) {
            $languages = Language::query()->orderBy('sort_order')->orderBy('id')->get();
        }

        $aiDataByLang = [];
        $titles = [];
        $slugs = [];

        foreach ($languages as $language) {
            $code = strtolower((string) $language->code);
            $desc = $product->descriptions->firstWhere('language_id', $language->id); //найди описание продукта для текущего языка

            $titles[$code] = $desc ? (string) $desc->name : '';
            $slugs[$code] = $desc ? (string) $desc->slug : '';

            $langPayload = [];
            if ($desc) {
                foreach (ProductDescription::aiFieldKeys() as $field) {
                    $raw = $desc->getAttribute($field);
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $normalized = $this->normalizeJsonLikeValue($raw);
                    if ($normalized === null) {
                        continue;
                    }

                    $langPayload[$field] = $normalized;
                }
            }

            $aiDataByLang[$code] = $langPayload;
        }

        /*

ai_data:
   en → {
       ai_text_about_the_country: {...},
       ai_seasons_line: {...},
       ai_faq: {...}
   }

   he → {
       ai_text_about_the_country: {...},
       ai_seasons_line: {...}
   }

   ar → {}

        */

        return [
            'product_id' => $product->id,
            'manufacturer_name' => $manufacturer->name,
            'titles' => $titles,
            'slugs' => $slugs,
            'ai_data' => $aiDataByLang,
        ];

/*
result
   ├── product_id: 15
   ├── manufacturer_name: "apple.com"

   ├── titles
   │       ├── en: "iPhone"
   │       ├── he: "אייפון"
   │       └── ar: ""

   ├── slugs
   │       ├── en: "iphone"
   │       ├── he: "iphone-he"
   │       └── ar: ""

   ├── ai_data
           ├── en: { ...данные... }
           ├── he: { ...данные... }
           └── ar: {}

*/


    }

    /**
     * Убирает markdown-ограждения и BOM, затем json_decode.
     */
    private function normalizeJsonLikeValue(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
        $s = trim($s);

        if (preg_match('/^```[a-zA-Z0-9_-]*\s*\R?(.*?)\R?```\s*$/s', $s, $m)) {
            $s = trim($m[1]);
        } else {
            $s = preg_replace('/^```(?:json)?\s*/i', '', $s) ?? $s;
            $s = preg_replace('/\s*```\s*$/', '', $s) ?? $s;
            $s = trim($s);
        }

        $decoded = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $decoded2 = json_decode(stripslashes($s), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded2;
        }

        return $s;
    }

    public function publish(Product $product): void
    {
        Log::info('[WordPressService] Старт публикации товара', ['product_id' => $product->id]);

        try {
              //  Собираем все данные товара (titles, slugs, ai_data и т.д.)
            $payload = $this->prepareData($product);

            Log::info('[WordPressService] Отправляемые данные:', $payload);
// Формируем URL WordPress на основе производителя
            $url = $this->determineUrl($payload['manufacturer_name']);


            // asJson() «я ОТПРАВЛЯЮ данные как JSON»
            // acceptJson() «я ХОЧУ получить ответ в JSON»
            $request = Http::timeout(30)->acceptJson()->asJson();
            if (config('app.env') === 'local') {
                $request = $request->withoutVerifying();
            }

            // Секрет webhook: если задан в .env, Laravel доказывает WordPress, что запрос «свой».
            // WordPress читает тот же секрет из константы LARAVEL_API_KEY и сравнивает заголовок
            // X-Laravel-Api-Key (регистр имени заголовка в HTTP не важен; Guzzle нормализует).
            // Если секрет пустой — заголовок не шлём; тогда WP должен быть в dev-режиме без ключа.
            $secret = config('services.wordpress.webhook_secret');
            if (! is_string($secret) || trim($secret) === '') {
                // Fallback на env, если config закэширован со старым/пустым значением.
                $secret = env('WORDPRESS_WEBHOOK_SECRET', '');
            }
            $urlForPost = $url;
            if (is_string($secret) && $secret !== '') {
                $secret = trim($secret);
                $request = $request->withHeaders(['X-Laravel-Api-Key' => $secret]);
                // Резерв для сред, где прокси/сервер не пробрасывает кастомный заголовок.
                $urlForPost = $url.(str_contains($url, '?') ? '&' : '?').'api_key='.rawurlencode($secret);
            }
            Log::info('[WordPressService] Auth diagnostics', [
                'product_id' => $product->id,
                'has_secret' => is_string($secret) && $secret !== '',
                'url_has_api_key' => str_contains($urlForPost, 'api_key='),
            ]);

            $response = $request->post($urlForPost, $payload);

            if ($response->failed()) {
                throw new \Exception('Ответ API: '.$response->body());
            }

            Log::info('[WordPressService] Успешно отправлено', ['product_id' => $product->id]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[WordPressService] Ошибка сети (timeout/dns)', ['error' => $e->getMessage()]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('[WordPressService] Ошибка публикации', ['product_id' => $product->id, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function determineUrl(string $manufacturerName): string
    {
        return 'https://'.trim($manufacturerName).'/wp-json/my-api/v1/create';
    }
}
