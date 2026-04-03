# Где находится middleware 'auth' и как он работает

## Расположение middleware 'auth':

### 1. Физическое расположение:
```
vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php
```

### 2. Регистрация в Laravel:
В Laravel 11+ middleware 'auth' **встроен по умолчанию** и доступен через алиас 'auth'.

### 3. Использование в роутах:
```php
// routes/web.php
Route::prefix('admin')
    ->middleware(['auth'])  // ← ВОТ ОН ЗДЕСЬ!
    ->group(function () {
        // ...
    });
```

## Как работает middleware 'auth':

### Процесс выполнения:

```
1. Пользователь переходит на /admin
        ↓
2. Laravel проверяет роуты
        ↓
3. Находит роут с middleware(['auth'])
        ↓
4. Выполняет Authenticate::handle()
        ↓
5. Проверяет: Auth::check() - залогинен ли пользователь?
        ↓
        ├─→ ДА ✅
        │       ↓
        │   Продолжает выполнение
        │   Запрос идет в контроллер
        │
        └─→ НЕТ ❌
                ↓
            Выбрасывает AuthenticationException
                ↓
            Редирект на /login
```

### Код middleware (упрощенно):

```php
// vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php

public function handle($request, Closure $next, ...$guards)
{
    $this->authenticate($request, $guards);  // Проверка авторизации
    
    return $next($request);  // Если OK - продолжаем
}

protected function authenticate($request, array $guards)
{
    foreach ($guards as $guard) {
        if ($this->auth->guard($guard)->check()) {
            // Пользователь залогинен ✅
            return;
        }
    }
    
    // Пользователь НЕ залогинен ❌
    $this->unauthenticated($request, $guards);
}

protected function unauthenticated($request, array $guards)
{
    throw new AuthenticationException(
        'Unauthenticated.',
        $guards,
        $request->expectsJson() ? null : $this->redirectTo($request)  // Редирект на /login
    );
}
```

## Что проверяет middleware 'auth':

1. **Проверяет сессию:**
   - Есть ли активная сессия?
   - Сохранен ли пользователь в сессии?

2. **Проверяет данные пользователя:**
   - Существует ли пользователь в БД?
   - Активна ли его сессия?

3. **Результат:**
   - ✅ Если залогинен → пропускает дальше
   - ❌ Если нет → редирект на `/login`

## Визуальная схема:

```
Запрос: GET /admin
    ↓
Роут найден: Route::prefix('admin')->middleware(['auth'])
    ↓
Middleware 'auth' выполняется
    ↓
Проверка: Auth::check()
    ↓
    ├─→ TRUE (залогинен) ✅
    │       ↓
    │   return $next($request)
    │       ↓
    │   Запрос идет в MainController@index
    │       ↓
    │   Показывается админка
    │
    └─→ FALSE (не залогинен) ❌
            ↓
        throw AuthenticationException
            ↓
        Редирект на /login
            ↓
        Пользователь видит форму входа
```

## Важно:

- Middleware 'auth' **НЕ проверяет роль** (admin/user)
- Он только проверяет: **залогинен ли пользователь вообще**
- Проверка роли происходит **в контроллере** (AdminLoginController)

## Два уровня защиты:

1. **Middleware 'auth'** (в роутах):
   - Проверяет: залогинен ли пользователь?
   - Если нет → редирект на /login

2. **Проверка роли** (в AdminLoginController):
   - Проверяет: role === 'admin'?
   - Если нет → logout + ошибка

