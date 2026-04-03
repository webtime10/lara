# Где находятся middleware и где они регистрируются

## 1. Физическое расположение middleware:

### Auth Middleware:
```
vendor/laravel/framework/src/Illuminate/Auth/Middleware/
├── Authenticate.php              ← Это 'auth'
├── AuthenticateWithBasicAuth.php ← Это 'auth.basic'
├── Authorize.php                 ← Это 'can'
├── EnsureEmailIsVerified.php     ← Это 'verified'
├── RedirectIfAuthenticated.php   ← Это 'guest'
└── RequirePassword.php           ← Это 'password.confirm'
```

### HTTP Middleware:
```
vendor/laravel/framework/src/Illuminate/Http/Middleware/
├── SetCacheHeaders.php           ← Это 'cache.headers'
├── TrimStrings.php
├── ConvertEmptyStringsToNull.php
└── ...
```

### Routing Middleware:
```
vendor/laravel/framework/src/Illuminate/Routing/Middleware/
├── ThrottleRequests.php          ← Это 'throttle'
├── ValidateSignature.php         ← Это 'signed'
└── ...
```

## 2. Где регистрируются алиасы:

### Файл регистрации:
```
vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php
```

### Метод defaultAliases() (строки 779-802):
```php
protected function defaultAliases()
{
    $aliases = [
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \Illuminate\Auth\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ];
    return $aliases;
}
```

## 3. Где можно добавить свои middleware:

### Файл: bootstrap/app.php
```php
->withMiddleware(function (Middleware $middleware): void {
    // Здесь можно добавить свои middleware
    $middleware->alias([
        'admin' => \App\Http\Middleware\AdminAccess::class,
    ]);
})
```

## 4. Как посмотреть все middleware:

### Через команду:
```bash
php artisan route:list --middleware
```

### Или в коде:
```php
// В tinker или контроллере
$middleware = app(\Illuminate\Foundation\Configuration\Middleware::class);
dd($middleware->getMiddlewareAliases());
```

## 5. Про Illuminate (не Illuminati! 😄):

**Illuminate** - это просто название namespace в Laravel, означает "освещать/просвещать" (to illuminate).

Это НЕ Illuminati! 😄 Это просто красивое название для фреймворка, который "освещает" путь разработки.

Структура:
```
Illuminate\
├── Auth\          (авторизация)
├── Http\          (HTTP запросы)
├── Routing\       (роутинг)
├── Foundation\    (основа фреймворка)
└── ...
```

## 6. Полный путь middleware 'auth':

```
1. routes/web.php
   ->middleware(['auth'])
        ↓
2. Laravel ищет алиас 'auth'
        ↓
3. vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php
   defaultAliases() → 'auth' => Authenticate::class
        ↓
4. vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php
   public function handle() → проверяет авторизацию
```

