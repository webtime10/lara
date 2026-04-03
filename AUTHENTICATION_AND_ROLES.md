# Обзор системы авторизации и ролей

## Содержание
1. [Обзор системы](#обзор-системы)
2. [Процесс авторизации](#процесс-авторизации)
3. [Система ролей](#система-ролей)
4. [Middleware и защита маршрутов](#middleware-и-защита-маршрутов)
5. [Проверка ролей в коде](#проверка-ролей-в-коде)
6. [Структура базы данных](#структура-базы-данных)
7. [Примеры использования](#примеры-использования)
8. [Безопасность](#безопасность)

---

## Обзор системы

Проект использует **сессионную авторизацию Laravel** с кастомной системой ролей. Система поддерживает:
- Авторизацию по email или имени пользователя
- Гибкую систему ролей через отдельную таблицу
- Защиту маршрутов через middleware
- Обратную совместимость со старой системой ролей

### Компоненты системы:

1. **AdminLoginController** - обработка входа/выхода
2. **User модель** - модель пользователя с методами проверки ролей
3. **Role модель** - модель ролей
4. **AdminAccess middleware** - защита админских маршрутов
5. **Конфигурация auth** - настройки Laravel Auth

---

## Процесс авторизации

### 1. Форма входа

**Маршрут:** `GET /login`  
**Контроллер:** `AdminLoginController@showLoginForm`  
**Представление:** `resources/views/auth/login.blade.php`

Пользователь видит форму с полями:
- `login` - email или имя пользователя
- `password` - пароль

### 2. Обработка входа

**Маршрут:** `POST /login`  
**Контроллер:** `AdminLoginController@login`

#### Алгоритм работы:

```php
1. Валидация данных:
   - login: required|string
   - password: required

2. Определение типа входа:
   - Если login содержит @ → ищем по email
   - Иначе → ищем по name

3. Поиск пользователя:
   $user = User::where($field, $login)->first();

4. Проверка пароля:
   Hash::check($password, $user->password)

5. Проверка наличия роли:
   - Загружаем роль через roleRelation
   - Проверяем role_id или старую колонку role
   - Если роли нет → выход и ошибка

6. Авторизация:
   Auth::login($user)
   $request->session()->regenerate()

7. Редирект:
   - Успех → /admin
   - Ошибка → назад с сообщением
```

#### Код метода `login()`:

```php
public function login(Request $request)
{
    $request->validate([
        'login' => ['required', 'string'],
        'password' => ['required'],
    ]);

    try {
        $login = $request->input('login');
        $password = $request->input('password');
        
        // Определяем тип входа
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';
        
        // Ищем пользователя
        $user = User::where($field, $login)->first();
        
        // Проверяем пароль
        if ($user && Hash::check($password, $user->password)) {
            // Авторизуем
            Auth::login($user);
            $request->session()->regenerate();
            
            // Проверяем роль
            $user->load('roleRelation');
            $hasRole = $user->role_id || ($user->attributes['role'] ?? null);
            
            if ($hasRole) {
                return redirect()->intended('/admin');
            }

            // Нет роли - выходим
            Auth::logout();
            return back()->withErrors(['login' => 'У пользователя не назначена роль.']);
        }

        return back()->withErrors(['login' => 'Неверный логин или пароль.']);
        
    } catch (\Exception $e) {
        \Log::error('Login error: ' . $e->getMessage());
        return back()->withErrors(['login' => 'Произошла ошибка при входе.']);
    }
}
```

### 3. Выход из системы

**Маршрут:** `POST /logout`  
**Контроллер:** `AdminLoginController@logout`

```php
public function logout(Request $request)
{
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/login');
}
```

**Что происходит:**
- Удаляется сессия пользователя
- Инвалидируется текущая сессия
- Регенерируется CSRF токен
- Редирект на страницу входа

---

## Система ролей

### Структура ролей

Система использует **двухуровневую структуру ролей**:

1. **Новая система** (рекомендуется):
   - Таблица `roles` с полями: `id`, `name`, `slug`, `description`
   - Связь через `users.role_id` → `roles.id`
   - Использование slug для проверки ролей

2. **Старая система** (legacy):
   - Поле `users.role` как строка
   - Поддерживается для обратной совместимости

### Модель Role

**Путь:** `app/Models/Role.php`

```php
class Role extends Model
{
    protected $fillable = [
        'name',        // Название роли (например, "Администратор")
        'slug',        // Уникальный идентификатор (например, "admin")
        'description', // Описание роли
    ];

    // Отношение: одна роль → много пользователей
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
```

**Примеры ролей:**
- `admin` - администратор (полный доступ)
- `moderator` - модератор (ограниченный доступ)
- `editor` - редактор (только редактирование контента)

### Модель User

**Путь:** `app/Models/User.php`

#### Поля модели:

```php
protected $fillable = [
    'name',      // Имя пользователя
    'email',     // Email (уникальный)
    'password',  // Хешированный пароль
    'role',      // Старая система (legacy)
    'role_id',   // ID роли (новая система)
];
```

#### Отношения:

```php
// Получить роль пользователя
public function roleRelation()
{
    return $this->belongsTo(Role::class, 'role_id');
}
```

#### Методы проверки ролей:

##### `hasRole($roleSlug)`

Проверяет, имеет ли пользователь указанную роль по slug.

```php
public function hasRole($roleSlug)
{
    // Проверка через новую систему (role_id)
    if ($this->role_id) {
        if (!$this->relationLoaded('roleRelation')) {
            $this->load('roleRelation');
        }
        if ($this->roleRelation && $this->roleRelation->slug === $roleSlug) {
            return true;
        }
    }
    
    // Fallback на старую систему (колонка role)
    $roleValue = $this->attributes['role'] ?? null;
    if ($roleValue && is_string($roleValue) && $roleValue === $roleSlug) {
        return true;
    }
    
    return false;
}
```

**Использование:**
```php
if ($user->hasRole('admin')) {
    // Пользователь администратор
}

if ($user->hasRole('moderator')) {
    // Пользователь модератор
}
```

##### `isAdmin()`

Сокращенный метод для проверки администратора.

```php
public function isAdmin()
{
    return $this->hasRole('admin');
}
```

**Использование:**
```php
if ($user->isAdmin()) {
    // Пользователь администратор
}
```

---

## Middleware и защита маршрутов

### 1. Стандартный middleware `auth`

**Что делает:**
- Проверяет, авторизован ли пользователь
- Если нет → редирект на `/login`

**Использование:**
```php
Route::middleware(['auth'])->group(function () {
    // Защищенные маршруты
});
```

### 2. Кастомный middleware `admin`

**Путь:** `app/Http/Middleware/AdminAccess.php`

**Регистрация:** `bootstrap/app.php`

```php
$middleware->alias([
    'admin' => \App\Http\Middleware\AdminAccess::class,
]);
```

#### Код middleware:

```php
class AdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Проверка авторизации
        if (!Auth::check()) {
            return redirect('/login');
        }

        // Проверка роли администратора
        $user = Auth::user();
        if (!$user->hasRole('admin')) {
            Auth::logout();
            return redirect('/login')
                ->withErrors(['login' => 'Доступ только для администраторов.']);
        }

        return $next($request);
    }
}
```

**Что делает:**
1. Проверяет авторизацию пользователя
2. Проверяет роль администратора через `hasRole('admin')`
3. Если не админ → выход и редирект с ошибкой
4. Если админ → пропускает запрос дальше

### 3. Защита маршрутов в `routes/web.php`

#### Базовый уровень защиты (требуется авторизация):

```php
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth'])  // Только авторизованные пользователи
    ->group(function () {
        
        // Доступны всем авторизованным пользователям
        Route::get('/', [MainController::class, 'index']);
        Route::resource('categories', CategoryController::class);
        Route::resource('companies', CompanyController::class);
        // ...
    });
```

#### Расширенный уровень защиты (только для админов):

```php
Route::middleware(['admin'])->group(function () {
    // Только для администраторов
    Route::resource('roles', RoleController::class);
    Route::resource('users', UserController::class);
});
```

**Иерархия защиты:**

```
Публичные маршруты (нет защиты)
    ↓
Маршруты с middleware 'auth' (требуется авторизация)
    ↓
Маршруты с middleware 'admin' (требуется роль admin)
```

### 4. Полная защита маршрута

Для маршрутов, которые должны быть доступны только админам:

```php
Route::prefix('admin')
    ->middleware(['auth', 'admin'])  // Оба middleware
    ->group(function () {
        // Только для администраторов
    });
```

**Примечание:** В текущем проекте используется вложенная структура:
- Внешний уровень: `middleware(['auth'])` - все авторизованные
- Внутренний уровень: `middleware(['admin'])` - только админы

---

## Проверка ролей в коде

### В контроллерах

#### Пример 1: Проверка в методе контроллера

```php
public function someMethod()
{
    $user = Auth::user();
    
    if ($user->hasRole('admin')) {
        // Логика для администратора
    } else {
        // Логика для обычного пользователя
    }
}
```

#### Пример 2: Проверка перед действием

```php
public function destroy($id)
{
    $user = Auth::user();
    
    // Нельзя удалить самого себя
    if ($user->id === auth()->id()) {
        return redirect()->back()
            ->with('error', 'Нельзя удалить самого себя.');
    }
    
    // Проверка роли
    if (!$user->isAdmin()) {
        abort(403, 'Доступ запрещен');
    }
    
    // Удаление...
}
```

### В представлениях (Blade)

```blade
@auth
    @if(auth()->user()->hasRole('admin'))
        <a href="{{ route('admin.users.index') }}">Управление пользователями</a>
    @endif
    
    @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.roles.index') }}">Управление ролями</a>
    @endif
@endauth
```

### В условиях

```php
// Простая проверка
if (auth()->user()->hasRole('admin')) {
    // ...
}

// Проверка нескольких ролей
if (auth()->user()->hasRole('admin') || auth()->user()->hasRole('moderator')) {
    // ...
}

// Использование isAdmin()
if (auth()->user()->isAdmin()) {
    // ...
}
```

---

## Структура базы данных

### Таблица `users`

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(255) NULL,           -- Legacy поле
    role_id BIGINT UNSIGNED NULL,     -- FK → roles.id
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
);
```

**Индексы:**
- `email` - уникальный индекс
- `role_id` - индекс для связи

### Таблица `roles`

```sql
CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

**Индексы:**
- `slug` - уникальный индекс

**Примеры записей:**

| id | name           | slug      | description              |
|----|----------------|-----------|--------------------------|
| 1  | Администратор  | admin     | Полный доступ к системе  |
| 2  | Модератор      | moderator | Ограниченный доступ      |
| 3  | Редактор       | editor    | Редактирование контента  |

### Связи

```
users (1) ←→ (many) roles
   ↓
role_id → roles.id
```

---

## Примеры использования

### Пример 1: Создание пользователя с ролью

```php
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

// Найти роль
$adminRole = Role::where('slug', 'admin')->first();

// Создать пользователя
$user = User::create([
    'name' => 'Иван Иванов',
    'email' => 'ivan@example.com',
    'password' => Hash::make('password123'),
    'role_id' => $adminRole->id,
]);
```

### Пример 2: Изменение роли пользователя

```php
$user = User::find(1);
$moderatorRole = Role::where('slug', 'moderator')->first();

$user->update([
    'role_id' => $moderatorRole->id,
]);
```

### Пример 3: Проверка доступа в контроллере

```php
class SomeController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        
        if ($user->isAdmin()) {
            // Полный список для админа
            $items = Item::all();
        } else {
            // Ограниченный список для остальных
            $items = Item::where('user_id', $user->id)->get();
        }
        
        return view('items.index', compact('items'));
    }
}
```

### Пример 4: Условный доступ в Blade

```blade
<div class="actions">
    <a href="{{ route('items.edit', $item) }}">Редактировать</a>
    
    @if(auth()->user()->isAdmin())
        <form action="{{ route('items.destroy', $item) }}" method="POST">
            @csrf
            @method('DELETE')
            <button type="submit">Удалить</button>
        </form>
    @endif
</div>
```

### Пример 5: Создание новой роли

```php
use App\Models\Role;

$role = Role::create([
    'name' => 'Менеджер',
    'slug' => 'manager',
    'description' => 'Управление контентом и компаниями',
]);
```

### Пример 6: Получение всех пользователей с ролью

```php
$adminRole = Role::where('slug', 'admin')->first();
$admins = $adminRole->users; // Коллекция пользователей
```

---

## Безопасность

### 1. Хеширование паролей

Пароли автоматически хешируются через Laravel:

```php
// В модели User
protected function casts(): array
{
    return [
        'password' => 'hashed',  // Автоматическое хеширование
    ];
}
```

**При создании пользователя:**
```php
User::create([
    'password' => Hash::make('plain_password'),
]);
```

### 2. Защита от SQL-инъекций

Laravel Eloquent автоматически защищает от SQL-инъекций через prepared statements.

### 3. CSRF защита

Все POST/PUT/DELETE запросы защищены CSRF токенами Laravel.

### 4. Регенерация сессии

После успешного входа сессия регенерируется:

```php
$request->session()->regenerate();
```

Это предотвращает фиксацию сессии (session fixation).

### 5. Проверка ролей на уровне middleware

Роли проверяются на уровне middleware перед доступом к контроллерам, что предотвращает прямой доступ к методам.

### 6. Валидация входных данных

Все данные валидируются перед обработкой:

```php
$request->validate([
    'email' => 'required|email|unique:users',
    'password' => 'required|min:6|confirmed',
]);
```

### 7. Логирование ошибок

Ошибки авторизации логируются:

```php
\Log::error('Login error: ' . $e->getMessage());
```

### Рекомендации по безопасности:

1. **Используйте сильные пароли** - минимум 8 символов, комбинация букв, цифр и символов
2. **Регулярно обновляйте зависимости** - для исправления уязвимостей
3. **Используйте HTTPS** - для защиты данных при передаче
4. **Ограничьте попытки входа** - добавьте rate limiting для `/login`
5. **Мониторинг** - отслеживайте неудачные попытки входа
6. **Двухфакторная аутентификация** - рассмотрите добавление 2FA для админов

---

## Часто задаваемые вопросы

### Как добавить новую роль?

1. Создайте запись в таблице `roles`:
```php
Role::create([
    'name' => 'Новая роль',
    'slug' => 'new_role',
    'description' => 'Описание роли',
]);
```

2. Используйте slug в проверках:
```php
if ($user->hasRole('new_role')) {
    // ...
}
```

### Как проверить несколько ролей?

```php
if ($user->hasRole('admin') || $user->hasRole('moderator')) {
    // Пользователь админ или модератор
}
```

### Как получить роль пользователя?

```php
// Через отношение
$role = $user->roleRelation;

// Через slug
$roleSlug = $user->roleRelation->slug ?? null;
```

### Как защитить отдельный метод контроллера?

```php
public function __construct()
{
    $this->middleware('admin')->only(['destroy', 'update']);
}
```

### Как создать пользователя через админку?

Используйте `Admin\UserController@store`:
- Маршрут: `POST /admin/users`
- Требуется роль: `admin`
- Автоматически хеширует пароль

---

## Заключение

Система авторизации и ролей в проекте обеспечивает:

✅ **Гибкость** - поддержка нескольких ролей  
✅ **Безопасность** - хеширование паролей, CSRF защита  
✅ **Удобство** - простые методы проверки ролей  
✅ **Масштабируемость** - легко добавлять новые роли  
✅ **Обратная совместимость** - поддержка старой системы ролей

Для работы с системой используйте:
- `Auth::user()` - получить текущего пользователя
- `$user->hasRole('slug')` - проверить роль
- `$user->isAdmin()` - проверить админа
- Middleware `auth` и `admin` - защита маршрутов

