# Стандартные подходы Laravel для ролей

## Текущая ситуация:

У вас уже есть:
- ✅ Колонка `role` в таблице `users` (строка в БД: `'admin'`, `'user'`, и т.д.)
- ✅ Проверка роли в `AdminLoginController@login`

---

## Два стандартных подхода Laravel:

### 1. Простой подход (рекомендуется для начала):
- Таблица `roles` (id, name, description)
- Связь `users.role_id` → `roles.id`
- Управление ролями в админке

### 2. Пакет Spatie Laravel Permission (для сложных систем):
- Полноценная система ролей и прав доступа
- Таблицы: `roles`, `permissions`, `role_has_permissions`, `model_has_roles`
- Гибкая система прав

---

## Рекомендация: Простой подход с таблицей `roles`

**Почему:**
- ✅ Стандартный подход Laravel
- ✅ Просто реализовать
- ✅ Легко управлять в админке
- ✅ Достаточно для большинства проектов

---

## Что нужно создать:

### 1. Миграция для таблицы `roles`:
```php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();  // 'admin', 'editor', 'user'
    $table->string('slug')->unique();  // 'admin', 'editor', 'user'
    $table->text('description')->nullable();
    $table->timestamps();
});
```

### 2. Миграция для связи `users.role_id`:
```php
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('role_id')->nullable()->after('email')->constrained('roles');
});
```

### 3. Модель `Role`:
```php
class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description'];
    
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
```

### 4. Обновить модель `User`:
```php
public function role()
{
    return $this->belongsTo(Role::class);
}

public function hasRole($roleSlug)
{
    return $this->role && $this->role->slug === $roleSlug;
}
```

### 5. Контроллер для управления ролями:
- `RoleController` - CRUD для ролей
- Список ролей, создание, редактирование, удаление

### 6. Views для управления ролями:
- `roles/index.blade.php` - список ролей
- `roles/create.blade.php` - создание роли
- `roles/edit.blade.php` - редактирование роли

---

## Пример использования:

### В контроллере:
```php
// Проверка роли
if ($user->hasRole('admin')) {
    // Доступ разрешен
}
```

### В middleware:
```php
if ($user->role->slug !== 'admin') {
    return redirect('/login');
}
```

### В Blade:
```blade
@if(auth()->user()->hasRole('admin'))
    <!-- Контент для админа -->
@endif
```

---

## Альтернатива: Spatie Laravel Permission

Если нужна более сложная система с правами доступа:

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

**Преимущества:**
- ✅ Гибкая система прав
- ✅ Множественные роли у пользователя
- ✅ Права доступа к конкретным действиям

**Недостатки:**
- ❌ Более сложная структура
- ❌ Больше таблиц в БД

---

## ✅ Реализовано:

### Создано:

1. ✅ **Миграция** `create_roles_table` - таблица ролей
2. ✅ **Миграция** `add_role_id_to_users_table` - связь users с roles
3. ✅ **Модель** `Role` - для работы с ролями
4. ✅ **Обновлена модель** `User` - методы `hasRole()`, `isAdmin()`, `role()`
5. ✅ **Контроллер** `RoleController` - CRUD для ролей
6. ✅ **Views** - `roles/index.blade.php`, `create.blade.php`, `edit.blade.php`
7. ✅ **Роуты** - `Route::resource('roles', RoleController::class)`
8. ✅ **Обновлен** `AdminLoginController` - использует `hasRole('admin')`

---

## Как использовать:

### 1. Запустить миграции:
```bash
php artisan migrate
```

### 2. Создать роли в админке:
- Зайти в `/admin/roles`
- Нажать "Создать роль"
- Заполнить: Название (Admin), Slug (admin), Описание

### 3. Назначить роль пользователю:
- В таблице `users` установить `role_id` = ID роли

### 4. Использовать в коде:
```php
// Проверка роли
if ($user->hasRole('admin')) {
    // Доступ разрешен
}

// Или
if ($user->isAdmin()) {
    // Админ
}
```

---

## Рекомендация:

**Всё готово! Это стандартный подход Laravel - не изобретаем велосипед!**

**Следующие шаги:**
1. Запустить миграции
2. Создать роли в админке (`/admin/roles`)
3. Назначить роли пользователям
4. Использовать `$user->hasRole('role_slug')` в коде

