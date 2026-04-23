<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Читать только через config('services.openai.*') / config('services.gemini.*').
    | При php artisan config:cache вызовы env() вне config/ возвращают null — ключи «не находились».
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        /** Список ключей через запятую (ротация); дублирует OPENAI_API_KEYS для config:cache. */
        'keys_csv' => env('OPENAI_API_KEYS', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 16384),
        'ai_article_min_chars' => (int) env('OPENAI_AI_ARTICLE_MIN_CHARS', 2500),
        'rate_limit_retries' => (int) env('OPENAI_RATE_LIMIT_RETRIES', 8),
        'rate_limit_wait_base_sec' => (int) env('OPENAI_RATE_LIMIT_WAIT_BASE_SEC', 10),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WordPress: общий секрет (API key) для входящего webhook
    |--------------------------------------------------------------------------
    |
    | Что это: одна и та же длинная случайная строка (как пароль), известная
    | только вашему Laravel и вашему WordPress. Laravel кладёт её в HTTP-заголовок
    | X-Laravel-Api-Key при POST на /wp-json/my-api/v1/create. WordPress в
    | permission_callback сравнивает заголовок с константой LARAVEL_API_KEY
    | в wp-config.php через hash_equals() (безопасное сравнение, без утечки по времени).
    |
    | Зачем: без этого любой в интернете мог бы дергать ваш REST-маршрут и создавать
    | посты. Это не шифрование тела запроса — только доказательство «запрос от своего сервера».
    |
    | Где задать: .env на стороне Laravel → WORDPRESS_WEBHOOK_SECRET=...
    | На стороне WP: define('LARAVEL_API_KEY', 'тот же текст');
    |
    */
    'wordpress' => [
        'webhook_secret' => env('WORDPRESS_WEBHOOK_SECRET'),
    ],

];
