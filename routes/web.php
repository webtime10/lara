<?php
use App\Http\Controllers\Admin\MainController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\ManufacturerController;
use App\Http\Controllers\Admin\PromptCategoryController;
use App\Http\Controllers\Admin\PromptController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WordPressController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Admin\TestController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Фронт: только ссылка на вход в админку
Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/catalog', function () {
    return redirect()->route('login');
})->name('catalog.index');

Route::get('/prompt-catalog', function () {
    return redirect()->route('login');
})->name('prompt-catalog.index');

// --- АВТОРИЗАЦИЯ ---
// Страница входа и обработка формы
Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AdminLoginController::class, 'login'])->name('login.post');
Route::post('/logout', [AdminLoginController::class, 'logout'])->name('logout');


// --- АДМИНКА (Защищенная) ---
// Middleware 'auth' проверяет, залогинен ли пользователь вообще.
// Если ты уже создал Middleware 'AdminAccess' (про который мы говорили раньше), 
// то добавь его сюда: ->middleware(['auth', 'admin'])
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth']) 
    ->group(function () {
        
        // Главная страница админки
        Route::get('/', [MainController::class, 'index'])->name('index');

        Route::get('test', [TestController::class, 'index'])->name('test');

        // Превью slug (Str::slug как на сервере) для автозаполнения в формах
        Route::get('slug-preview', function (\Illuminate\Http\Request $request) {
            return response()->json([
                'slug' => \Illuminate\Support\Str::slug($request->query('text', '')),
            ]);
        })->name('slug.preview');

        Route::get('api-check/openai', function () {
            $apiKey = config('services.openai.key');
            if (! $apiKey) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Нет подключения: OPENAI_API_KEY не задан.',
                ], 500);
            }

            try {
                $client = \OpenAI::client($apiKey);
                $client->chat()->create([
                    'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
                    'max_tokens' => 8,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Ответь только OK'],
                    ],
                ]);

                return response()->json([
                    'ok' => true,
                    'message' => 'OK, есть подключение OpenAI.',
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Нет подключения OpenAI: '.$e->getMessage(),
                ], 500);
            }
        })->name('api-check.openai');

        Route::get('api-check/gemini', function () {
            $apiKey = config('services.gemini.key');
            if (! $apiKey) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Нет подключения: GEMINI_API_KEY не задан.',
                ], 500);
            }

            $model = (string) config('services.gemini.model', 'gemini-2.5-flash');
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                .rawurlencode($model)
                .':generateContent';

            try {
                $response = Http::timeout(20)->post($url.'?key='.urlencode($apiKey), [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => 'Ответь только OK'],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 8,
                    ],
                ]);

                if (! $response->successful()) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'Нет подключения Gemini: HTTP '.$response->status(),
                    ], 500);
                }

                return response()->json([
                    'ok' => true,
                    'message' => 'OK, есть подключение Gemini.',
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Нет подключения Gemini: '.$e->getMessage(),
                ], 500);
            }
        })->name('api-check.gemini');

        // Ресурсы
        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::resource('languages', LanguageController::class)->except(['show']);
        Route::post('prompt-categories/extraction-prompt', [PromptCategoryController::class, 'updateExtractionPrompt'])->name('prompt-categories.update-extraction-prompt');
        Route::post('prompt-categories/{id}/raw-data', [PromptCategoryController::class, 'updateRawData'])->name('prompt-categories.update-raw-data');
        Route::resource('prompt-categories', PromptCategoryController::class)->except(['show']);
        Route::resource('products', ProductController::class)->except(['show']);
        Route::delete('products', [ProductController::class, 'bulkDestroy'])->name('products.bulk_destroy');
        Route::resource('prompts', PromptController::class)->except(['show']);
        Route::resource('manufacturers', ManufacturerController::class)->except(['show']);
        
        // Только для админов
        Route::middleware(['admin'])->group(function () {
            Route::resource('roles', RoleController::class)->except(['show']);
            Route::resource('users', UserController::class)->except(['show']);
        });

        // GET /admin/products/{id}/check-ai-status?field=... — имя: admin.products.check_ai_status (префикс admin. из группы)
        Route::get('products/{id}/check-ai-status', [ProductController::class, 'checkAiStatus'])
            ->name('products.check_ai_status');

        Route::post('products/generate-ai', [ProductController::class, 'generateAi'])->name('products.generate_ai');
        Route::post('products/{product}/publish-wordpress', [WordPressController::class, 'publish'])->name('products.publish_wordpress');

        Route::post('products/extract-text', [ProductController::class, 'extractText'])->name('products.extract_text');

// тест аи подключения
        // --- ТЕСТ OpenAI (Потом удалим) ---
        Route::get('/test-ai', function () {
            // 1. Проверяем наличие ключа в .env
            $apiKey = config('services.openai.key');

            if (! $apiKey) {
                return 'Ошибка: задайте OPENAI_API_KEY в .env и выполните php artisan config:clear (или config:cache после правок .env).';
            }

            try {
                // 2. Создаем клиент
                $client = OpenAI::client($apiKey);

                // 3. Делаем простой запрос к дешевой модели mini
                $result = $client->chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'user', 'content' => 'Привет! Это Дима из Одессы. Если ты меня слышишь, ответь: "Конвейер запущен!"'],
                    ],
                ]);

                // 4. Выводим ответ на экран
                return "<h1>Ответ от ИИ:</h1><p>" . $result->choices[0]->message->content . "</p>";

            } catch (\Exception $e) {
                // Если будет ошибка (например, SSL или неверный ключ), мы её увидим
                return "Произошла ошибка: " . $e->getMessage();
            }
        });
        // Тест Gemini (URL: /admin/check-gemini)
        Route::get('/check-gemini', function () {
            $apiKey = config('services.gemini.key');
            if (! $apiKey) {
                return response('Ошибка: задайте GEMINI_API_KEY в .env и обновите кэш конфига (config:clear / config:cache).', 500);
            }

            $maskKey = static fn (string $urlWithKey): string => preg_replace('/key=[^&]+/u', 'key=***', $urlWithKey);

            $base = 'https://generativelanguage.googleapis.com/v1beta/models/';
            // Порядок: сначала то, что обычно доступно в AI Studio; gemini-1.5-flash без суффикса часто даёт 404
            $preferredModels = [
                'gemini-2.5-flash',
                'gemini-2.0-flash',
                'gemini-flash-latest',
                'gemini-2.0-flash-lite',
                'gemini-1.5-flash-8b',
                'gemini-1.5-pro-latest',
            ];

            $listOk = null;
            $modelsWithGenerate = [];
            try {
                $listResp = Http::timeout(20)->get(
                    'https://generativelanguage.googleapis.com/v1beta/models',
                    ['key' => $apiKey]
                );
                $listOk = $listResp->status();
                if ($listResp->ok()) {
                    foreach ($listResp->json('models') ?? [] as $row) {
                        $name = $row['name'] ?? '';
                        $methods = $row['supportedGenerationMethods'] ?? [];
                        if (! in_array('generateContent', $methods, true)) {
                            continue;
                        }
                        if (preg_match('#^models/(.+)$#', $name, $m)) {
                            $modelsWithGenerate[] = $m[1];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $listOk = 'error: ' . $e->getMessage();
            }

            $ordered = array_values(array_intersect($preferredModels, $modelsWithGenerate));
            if ($ordered === [] && $modelsWithGenerate !== []) {
                $ordered = array_slice($modelsWithGenerate, 0, 6);
            } elseif ($ordered === []) {
                $ordered = $preferredModels;
            }
            $ordered = array_slice(array_unique($ordered), 0, 6);

            $payload = [
                'contents' => [['parts' => [['text' => "Напиши 'Связь есть' на иврите."]]]],
            ];

            $attempts = [];

            foreach ($ordered as $model) {
                $url = $base . rawurlencode($model) . ':generateContent?key=' . $apiKey;
                try {
                    $lastResponse = Http::timeout(30)
                        ->acceptJson()
                        ->asJson()
                        ->post($url, $payload);

                    $attempts[] = [
                        'model' => $model,
                        'status' => $lastResponse->status(),
                        'body' => $lastResponse->json() ?? $lastResponse->body(),
                    ];

                    if ($lastResponse->successful()) {
                        $answer = $lastResponse->json('candidates.0.content.parts.0.text');

                        return response()->json([
                            'status' => 'ok',
                            'model' => $model,
                            'url_pattern' => $maskKey($url),
                            'answer' => $answer,
                            'list_models_http_status' => $listOk,
                            'models_with_generateContent_count' => count($modelsWithGenerate),
                        ], 200, [], JSON_UNESCAPED_UNICODE);
                    }
                } catch (\Throwable $e) {
                    $attempts[] = [
                        'model' => $model,
                        'status' => null,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $hints = [];
            foreach ($attempts as $a) {
                $st = $a['status'] ?? null;
                if ($st === 429) {
                    $hints[] = '429 RESOURCE_EXHAUSTED: исчерпана квота (часто free tier: лимит запросов в минуту/день или «limit: 0»). Подождите время из retry (или ~1 мин), проверьте https://ai.google.dev/gemini-api/docs/rate-limits и кабинет ключа в Google AI Studio; при необходимости включите биллинг для проекта.';
                }
                if ($st === 404) {
                    $hints[] = '404: для этого ключа/API такая модель недоступна. Смотрите models_with_generateContent в ответе (из ListModels).';
                }
            }
            $hints = array_values(array_unique($hints));

            return response()->json([
                'status' => 'failed',
                'hints' => $hints ?: [
                    'Проверьте ключ в Google AI Studio и что для проекта доступен Gemini API.',
                ],
                'list_models_http_status' => $listOk,
                'models_with_generateContent' => array_slice($modelsWithGenerate, 0, 40),
                'attempts' => $attempts,
            ], 502, [], JSON_UNESCAPED_UNICODE);
        })->name('check-gemini');


        
        
        
    });