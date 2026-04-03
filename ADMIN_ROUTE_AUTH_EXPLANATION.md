# Что происходит когда заходишь на `/admin` с middleware `auth`

## Ваш код в `routes/web.php`:

```php
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth'])  // ← Встроенный middleware Laravel
    ->group(function () {
        Route::get('/', [MainController::class, 'index'])->name('index');
        // ... другие роуты
    });
```

---

## Как это работает:

```
GET /admin
  ↓
middleware(['auth']) → Проверка сессии (Auth::check())
  ↓
  ├─ TRUE  → Попадает в группу → MainController@index
  └─ FALSE → НЕ попадает в группу → Редирект на /login
```

**Всё просто:**
- `middleware(['auth'])` - **встроенный в Laravel**
- Проверяет **сессию** (залогинен ли пользователь)
- `true` → попадаешь в группу роутов
- `false` → не попадаешь в группу, редирект на `/login`

---

## Важно:

### `middleware(['auth'])` проверяет ТОЛЬКО:
- **Залогинен ли пользователь?** (проверка сессии)
- **НЕ проверяет роль!**

### Проверка роли происходит в:
- `AdminLoginController@login` (строка 32)

---

## Если хотите свой middleware:

Можно написать свой, но здесь используется **встроенный** `auth`.

Для проверки роли используйте ваш `AdminAccess`:

```php
Route::prefix('admin')
    ->middleware(['auth', 'admin'])  // ← встроенный + ваш кастомный
    ->group(function () {
        // ...
    });
```

---

## Итого:

| Проверка сессии | Результат |
|-----------------|-----------|
| `true` | Попадаешь в группу → `MainController@index` |
| `false` | НЕ попадаешь в группу → Редирект на `/login` |

**`middleware(['auth'])` = встроенный Laravel, проверяет сессию**

