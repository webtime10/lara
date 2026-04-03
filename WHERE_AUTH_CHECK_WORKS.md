# ГДЕ и КАК проверяется Auth::check()

## Ваш код:
```php
Route::prefix('admin')
    ->middleware(['auth'])  // ← Проверяет Auth::check()
    ->group(function () {
        Route::get('/', [MainController::class, 'index']);
    });
```

---

## ГДЕ находится проверка:

### 1. Метод `check()`:

**Файл:** `vendor/laravel/framework/src/Illuminate/Auth/GuardHelpers.php`

**Строка 54:**
```php
public function check()
{
    return ! is_null($this->user());  // ← Вызывает user()
}
```

**Что делает:** Проверяет, есть ли пользователь (не null)

---

### 2. Метод `user()` - ГДЕ РЕАЛЬНО ПРОВЕРЯЕТСЯ:

**Файл:** `vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php`

**Строка 168:**
```php
public function user()
{
    if ($this->loggedOut) {
            return null;
    }

    // Если уже загружен пользователь - возвращает его
    if (! is_null($this->user)) {
        return $this->user;
    }

    // ← ВОТ ТУТ ПРОВЕРКА СЕССИИ!
    $id = $this->session->get($this->getName());  // ← Строка 181: Берет ID из сессии

    if (! is_null($id) && $this->user = $this->provider->retrieveById($id)) {
        // Строка 186: Загружает пользователя из БД по ID
        $this->fireAuthenticatedEvent($this->user);
    }

    return $this->user;  // ← Строка 203: Возвращает пользователя или null
}
```

---

## КАК это работает:

### Шаг 1: Проверка сессии

```php
$id = $this->session->get($this->getName());
```

**Что происходит:**
- Берет из **сессии** ключ `login_web_...` (ID пользователя)
- Если ключа нет → `$id = null`

**Где хранится сессия:**
- В вашем случае: **база данных** (таблица `sessions`)
- Настройка: `config/session.php` → `'driver' => 'database'`

---

### Шаг 2: Если ID найден в сессии

```php
if (! is_null($id) && $this->user = $this->provider->retrieveById($id)) {
    // Загружает пользователя из БД по ID
}
```

**Что происходит:**
1. Берет ID из сессии (например, `1`)
2. Ищет пользователя в таблице `users` по ID
3. Если найден → `$this->user = User::find($id)`
4. Если не найден → `$this->user = null`

---

### Шаг 3: Возврат результата

```php
return $this->user;  // User объект или null
```

**Если пользователь найден:**
- `Auth::check()` → `true`
- Пропускает в контроллер

**Если пользователь НЕ найден:**
- `Auth::check()` → `false`
- Редирект на `/login`

---

## ГДЕ хранится информация о залогиненном пользователе:

### 1. Сессия (база данных):

**Таблица:** `sessions` (настройка в `config/session.php`)

**Структура:**
```
id          | user_id | ip_address | user_agent | payload                    | last_activity
------------|---------|------------|------------|----------------------------|---------------
abc123...   | 1       | 127.0.0.1  | Chrome...  | login_web_59ba36ab...=1    | 1234567890
```

**Ключ в сессии:** `login_web_59ba36ab...` = `1` (ID пользователя)

**Где создается:**
- При `Auth::attempt()` в `AdminLoginController@login` (строка 26)
- Laravel сохраняет ID пользователя в сессию

---

### 2. Cookie в браузере:

**Имя:** `laracatalog.loc-session` (или другое, настройка в `config/session.php`)

**Содержит:** ID сессии (например, `abc123...`)

**Что делает:**
- Браузер отправляет cookie с каждым запросом
- Laravel находит сессию по ID из cookie
- Берет `user_id` из сессии
- Загружает пользователя из БД

---

## Полный процесс проверки:

```
1. GET /admin
   ↓
2. middleware(['auth']) → Auth::check()
   ↓
3. GuardHelpers.php:54 → check()
   ↓
4. SessionGuard.php:168 → user()
   ↓
5. Берет ID из сессии: $this->session->get('login_web_...')
   ├─ Источник: таблица `sessions` в БД
   ├─ Ключ: cookie `laracatalog.loc-session` → ID сессии
   └─ Значение: `user_id` (например, `1`)
   ↓
6. Если ID найден:
   ├─ Загружает пользователя: User::find($id)
   ├─ Из таблицы: `users`
   └─ Возвращает: User объект
   ↓
7. Если ID НЕ найден:
   └─ Возвращает: null
   ↓
8. check() проверяет: ! is_null($this->user())
   ├─ Если User объект → TRUE → пропускает в контроллер
   └─ Если null → FALSE → редирект на /login
```

---

## Конкретные файлы:

| Что | Где находится |
|-----|---------------|
| **Метод `check()`** | `vendor/.../GuardHelpers.php:54` |
| **Метод `user()`** | `vendor/.../SessionGuard.php:168` |
| **Проверка сессии** | `SessionGuard.php:175` → `$this->session->get(...)` |
| **Загрузка из БД** | `SessionGuard.php:177` → `$this->provider->retrieveById($id)` |
| **Хранение сессии** | Таблица `sessions` в БД |
| **Настройка сессии** | `config/session.php` → `'driver' => 'database'` |
| **Настройка auth** | `config/auth.php` → `'driver' => 'session'` |

---

## Итого:

**Auth::check() проверяет:**
1. **Сессию** (таблица `sessions` в БД) → берет `user_id`
2. **Базу данных** (таблица `users`) → загружает пользователя по ID
3. **Возвращает:** `true` если пользователь найден, `false` если нет

**Хранится в:**
- **Сессия:** таблица `sessions` (ключ `login_web_...` = `user_id`)
- **Cookie:** `laracatalog.loc-session` (ID сессии для связи)

**Создается при:**
- `Auth::attempt()` в `AdminLoginController@login` (строка 26)

