# Структура таблицы sessions в базе данных

## Ваша настройка:

**Файл:** `config/session.php`

```php
'driver' => env('SESSION_DRIVER', 'database'),  // ← Хранится в БД
'table' => env('SESSION_TABLE', 'sessions'),     // ← Таблица 'sessions'
```

---

## Стандартная структура таблицы `sessions` в Laravel:

### Создание таблицы:

**Команда:**
```bash
php artisan session:table
```

**Или миграция вручную:**

```php
Schema::create('sessions', function (Blueprint $table) {
    $table->string('id')->primary();           // ID сессии
    $table->foreignId('user_id')->nullable()->index();  // ID пользователя (если залогинен)
    $table->string('ip_address', 45)->nullable();      // IP адрес
    $table->text('user_agent')->nullable();            // User Agent браузера
    $table->longText('payload');                       // Данные сессии (зашифрованные)
    $table->integer('last_activity')->index();         // Время последней активности
});
```

---

## Структура таблицы:

| Колонка | Тип | Описание |
|---------|-----|----------|
| `id` | string (primary) | Уникальный ID сессии (из cookie) |
| `user_id` | foreignId (nullable) | ID пользователя (если залогинен) |
| `ip_address` | string(45) | IP адрес клиента |
| `user_agent` | text | User Agent браузера |
| `payload` | longText | **Данные сессии (зашифрованные)** |
| `last_activity` | integer | Timestamp последней активности |

---

## Что хранится в `payload`:

**Данные сессии (зашифрованные):**
```php
// Внутри payload хранится:
[
    'login_web_59ba36ab...' => 1,  // ← ID пользователя (ключ Laravel)
    'other_session_data' => '...',
    // ... другие данные сессии
]
```

**Ключ:** `login_web_...` = ID пользователя  
**Значение:** `1` (user_id)

---

## Как Laravel использует таблицу:

### 1. При входе (`Auth::attempt()`):

```php
// AdminLoginController@login (строка 26)
Auth::attempt($credentials)
```

**Что происходит:**
1. Проверяет email/password в таблице `users`
2. Если ОК → создает запись в таблице `sessions`:
   ```
   id: 'abc123...' (из cookie)
   user_id: 1
   payload: 'login_web_...=1' (зашифровано)
   last_activity: 1234567890
   ```

### 2. При проверке (`Auth::check()`):

```php
// SessionGuard.php:181
$id = $this->session->get($this->getName());  // ← Берет из payload
```

**Что происходит:**
1. Берет ID сессии из cookie
2. Ищет запись в таблице `sessions` по `id`
3. Расшифровывает `payload`
4. Берет `login_web_...` = `user_id`
5. Загружает пользователя из таблицы `users` по `user_id`

---

## Пример данных в таблице `sessions`:

```
id              | user_id | ip_address | user_agent      | payload                    | last_activity
----------------|---------|------------|-----------------|----------------------------|---------------
abc123def456... | 1       | 127.0.0.1  | Mozilla/5.0...  | login_web_59ba36ab...=1     | 1705123456
xyz789ghi012... | NULL    | 127.0.0.1  | Chrome/120...   | (пусто)                    | 1705123400
```

**Первая запись:** Пользователь залогинен (user_id = 1)  
**Вторая запись:** Гость (user_id = NULL)

---

## Связь с таблицей `users`:

```
sessions.user_id → users.id
```

**Когда пользователь залогинен:**
- `sessions.user_id` = `1`
- `users.id` = `1`
- Laravel загружает: `User::find(1)`

**Когда пользователь НЕ залогинен:**
- `sessions.user_id` = `NULL`
- `Auth::check()` = `false`

---

## Важно:

### Структура должна соответствовать стандарту Laravel:

1. **Таблица `sessions`** - стандартная структура Laravel
2. **Колонка `payload`** - хранит зашифрованные данные сессии
3. **Ключ `login_web_...`** - стандартный ключ Laravel для ID пользователя
4. **Связь `user_id`** - внешний ключ на таблицу `users`

### Если структура не соответствует:

- Laravel не сможет найти данные сессии
- `Auth::check()` всегда вернет `false`
- Пользователь не сможет залогиниться

---

## Создание таблицы:

### Вариант 1: Команда Laravel (рекомендуется)

```bash
php artisan session:table
php artisan migrate
```

### Вариант 2: Вручную

Создайте миграцию:
```bash
php artisan make:migration create_sessions_table
```

И используйте стандартную структуру из `database.stub`.

---

## Итого:

| Что | Где |
|-----|-----|
| **Хранение сессии** | Таблица `sessions` в БД |
| **Структура** | Стандартная Laravel (6 колонок) |
| **ID пользователя** | В `payload` (ключ `login_web_...`) И в `user_id` |
| **Связь** | `sessions.user_id` → `users.id` |
| **Создание** | `php artisan session:table` |

---

## ✅ Вы правы!

**Использовать стандартную структуру Laravel лучше, чем писать свою:**

1. ✅ **Защищено** - Laravel знает, как правильно работать с этой структурой
2. ✅ **Совместимо** - гарантированно работает со всеми версиями Laravel
3. ✅ **Безопасно** - правильное шифрование и хранение данных
4. ✅ **Типично** - стандартный подход для всех Laravel проектов

**Создайте таблицу стандартной командой:**
```bash
php artisan session:table
php artisan migrate
```

**После этого авторизация будет работать правильно!**

**Структура должна соответствовать стандарту Laravel, иначе авторизация не будет работать!**

