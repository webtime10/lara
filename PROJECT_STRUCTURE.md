# Документация структуры проекта Laravel Catalog

## Содержание
1. [Обзор проекта](#обзор-проекта)
2. [Маршруты (Routes)](#маршруты-routes)
3. [Контроллеры (Controllers)](#контроллеры-controllers)
4. [Модели (Models)](#модели-models)
5. [Архитектура и паттерны](#архитектура-и-паттерны)

---

## Обзор проекта

Это Laravel-приложение для управления каталогом компаний и маркетплейсом. Проект использует многоязычность, иерархические категории и систему ролей для управления пользователями.

### Основные возможности:
- **Многоязычность**: Поддержка нескольких языков через модель `Language`
- **Категории**: Иерархическая система категорий с типами (directory, marketplace)
- **Компании**: Управление компаниями в справочнике
- **Маркетплейс**: Объявления с ценами и описаниями
- **Админ-панель**: Полнофункциональная админка с авторизацией
- **Роли и пользователи**: Система управления ролями и пользователями

---

## Маршруты (Routes)

### Файл: `routes/web.php`

#### Публичные маршруты

```php
// Главная страница сайта
GET  / → view('welcome')
```

#### Маршруты авторизации

```php
GET  /login → AdminLoginController@showLoginForm (name: 'login')
POST /login → AdminLoginController@login (name: 'login.post')
POST /logout → AdminLoginController@logout (name: 'logout')
```

**Описание:**
- `showLoginForm()` - отображает форму входа
- `login()` - обрабатывает вход (поддерживает email или name)
- `logout()` - выход из системы

#### Админ-маршруты (префикс: `/admin`, middleware: `auth`)

**Главная страница админки:**
```php
GET /admin → MainController@index (name: 'admin.index')
```

**Редактирование главной страницы:**
```php
GET /admin/home/edit → HomeController@edit (name: 'admin.home.edit')
PUT /admin/home → HomeController@update (name: 'admin.home.update')
```

**Ресурсные маршруты (CRUD):**

1. **Категории** (`categories`)
   - `GET /admin/categories` → `index()` - список категорий
   - `GET /admin/categories/create` → `create()` - форма создания
   - `POST /admin/categories` → `store()` - сохранение
   - `GET /admin/categories/{id}/edit` → `edit()` - форма редактирования
   - `PUT /admin/categories/{id}` → `update()` - обновление
   - `DELETE /admin/categories/{id}` → `destroy()` - удаление

2. **Языки** (`languages`)
   - Полный CRUD набор (кроме `show`)
   - Защита от удаления языка по умолчанию

3. **Компании** (`companies`)
   - Полный CRUD набор с фильтрацией по категориям
   - Поддержка загрузки логотипов
   - Связь с `DirectoryCompany` для дополнительного контента

4. **Объявления маркетплейса** (`marketplace_listings`)
   - Полный CRUD набор
   - Фильтрация по категориям
   - Связь с `ListingPost` для описаний

**Маршруты только для админов** (middleware: `admin`):

5. **Роли** (`roles`)
   - Полный CRUD набор
   - Защита от удаления ролей с пользователями

6. **Пользователи** (`users`)
   - Полный CRUD набор
   - Защита от удаления самого себя
   - Управление паролями и ролями

---

## Контроллеры (Controllers)

### Базовая структура

Все контроллеры наследуются от `App\Http\Controllers\Controller`.

### 1. `App\Http\Controllers\Controller`
**Путь:** `app/Http/Controllers/Controller.php`

Базовый абстрактный класс для всех контроллеров.

---

### 2. `App\Http\Controllers\Auth\AdminLoginController`
**Путь:** `app/Http/Controllers/Auth/AdminLoginController.php`

**Методы:**

#### `showLoginForm()`
- **Маршрут:** `GET /login`
- **Описание:** Отображает форму входа
- **Возвращает:** `view('auth.login')`

#### `login(Request $request)`
- **Маршрут:** `POST /login`
- **Описание:** Обрабатывает вход пользователя
- **Логика:**
  - Принимает `login` (email или name) и `password`
  - Определяет тип входа (email или name)
  - Проверяет пароль через `Hash::check()`
  - Проверяет наличие роли у пользователя
  - Авторизует через `Auth::login()`
  - Редирект в `/admin` при успехе
- **Валидация:**
  - `login`: required|string
  - `password`: required

#### `logout(Request $request)`
- **Маршрут:** `POST /logout`
- **Описание:** Выход из системы
- **Логика:** Очищает сессию и редиректит на `/login`

---

### 3. `App\Http\Controllers\Admin\MainController`
**Путь:** `app/Http/Controllers/Admin/MainController.php`

**Методы:**

#### `index()`
- **Маршрут:** `GET /admin`
- **Описание:** Главная страница админ-панели
- **Возвращает:** `view('admin.index')` с `$pageTitle = 'Admin Panel'`

---

### 4. `App\Http\Controllers\Admin\HomeController`
**Путь:** `app/Http/Controllers/Admin/HomeController.php`

**Методы:**

#### `edit()`
- **Маршрут:** `GET /admin/home/edit`
- **Описание:** Форма редактирования главной страницы
- **Логика:**
  - Получает или создает запись `Home` (singleton)
  - Загружает активные языки
  - Передает данные в представление
- **Возвращает:** `view('admin.home.edit')` с `$home`, `$languages`, `$pageTitle`

#### `update(Request $request)`
- **Маршрут:** `PUT /admin/home`
- **Описание:** Обновление данных главной страницы
- **Валидация:**
  - `date`: nullable|date
  - `title_{lang_code}`: nullable|string|max:255 (для каждого языка)
  - `description_{lang_code}`: nullable|string (для каждого языка)
- **Логика:**
  - Динамически строит массивы `title` и `description` для каждого языка
  - Обновляет модель `Home` с JSON-полями
- **Возвращает:** Редирект с сообщением об успехе

---

### 5. `App\Http\Controllers\Admin\CategoryController`
**Путь:** `app/Http/Controllers/Admin/CategoryController.php`

**Методы:**

#### `index()`
- **Маршрут:** `GET /admin/categories`
- **Описание:** Список всех категорий с пагинацией
- **Логика:**
  - Загружает категории с родительскими (`with('parent')`)
  - Сортировка по ID (desc)
  - Пагинация: 15 записей на страницу
- **Возвращает:** `view('admin.categories.index')`

#### `create()`
- **Маршрут:** `GET /admin/categories/create`
- **Описание:** Форма создания категории
- **Логика:**
  - Загружает родительские категории (без родителей)
  - Загружает активные языки
- **Возвращает:** `view('admin.categories.create')`

#### `store(Request $request)`
- **Маршрут:** `POST /admin/categories`
- **Описание:** Создание новой категории
- **Валидация:**
  - `slug`: required|string|max:255|unique:categories,slug
  - `parent_id`: nullable|exists:categories,id
  - `type`: required|string|max:50
  - `name_{lang_code}`: required для языка по умолчанию, nullable для остальных
- **Логика:**
  - Строит массив `name` для всех языков
  - Создает категорию с JSON-полем `name`
- **Возвращает:** Редирект на список с сообщением об успехе

#### `edit(string $id)`
- **Маршрут:** `GET /admin/categories/{id}/edit`
- **Описание:** Форма редактирования категории
- **Логика:**
  - Исключает текущую категорию из списка родительских
- **Возвращает:** `view('admin.categories.edit')`

#### `update(Request $request, string $id)`
- **Маршрут:** `PUT /admin/categories/{id}`
- **Описание:** Обновление категории
- **Валидация:** Аналогична `store()`, но с проверкой уникальности slug исключая текущую запись
- **Возвращает:** Редирект на список с сообщением об успехе

#### `destroy(string $id)`
- **Маршрут:** `DELETE /admin/categories/{id}`
- **Описание:** Удаление категории
- **Возвращает:** Редирект на список с сообщением об успехе

---

### 6. `App\Http\Controllers\Admin\LanguageController`
**Путь:** `app/Http/Controllers/Admin/LanguageController.php`

**Методы:**

#### `index()`
- **Маршрут:** `GET /admin/languages`
- **Описание:** Список языков
- **Логика:** Сортировка: сначала язык по умолчанию, затем по ID
- **Возвращает:** `view('admin.languages.index')`

#### `create()`
- **Маршрут:** `GET /admin/languages/create`
- **Описание:** Форма создания языка
- **Возвращает:** `view('admin.languages.create')`

#### `store(Request $request)`
- **Маршрут:** `POST /admin/languages`
- **Описание:** Создание языка
- **Валидация:**
  - `code`: required|string|max:10|unique:languages,code
  - `name`: required|string|max:100
  - `is_default`: nullable|boolean
  - `is_active`: nullable|boolean
- **Логика:**
  - Если устанавливается как язык по умолчанию, снимает флаг у остальных
- **Возвращает:** Редирект на список

#### `edit(string $id)`
- **Маршрут:** `GET /admin/languages/{id}/edit`
- **Описание:** Форма редактирования языка
- **Возвращает:** `view('admin.languages.edit')`

#### `update(Request $request, string $id)`
- **Маршрут:** `PUT /admin/languages/{id}`
- **Описание:** Обновление языка
- **Логика:** Аналогична `store()`, но с проверкой уникальности code
- **Возвращает:** Редирект на список

#### `destroy(string $id)`
- **Маршрут:** `DELETE /admin/languages/{id}`
- **Описание:** Удаление языка
- **Защита:**
  - Нельзя удалить язык по умолчанию
  - Нельзя удалить последний активный язык
- **Возвращает:** Редирект на список с сообщением об ошибке или успехе

---

### 7. `App\Http\Controllers\Admin\CompanyController`
**Путь:** `app/Http/Controllers/Admin/CompanyController.php`

**Методы:**

#### `index(Request $request)`
- **Маршрут:** `GET /admin/companies`
- **Описание:** Список компаний с фильтрацией
- **Фильтры:**
  - `category_id` - фильтр по категории
  - `search` - поиск по названию (JSON-поле)
- **Логика:**
  - Загружает связи `category` и `directoryCompany`
  - Поддерживает поиск по JSON-полю `title`
- **Возвращает:** `view('admin.companies.index')`

#### `create()`
- **Маршрут:** `GET /admin/companies/create`
- **Описание:** Форма создания компании
- **Логика:**
  - Загружает иерархию категорий типа `directory`
  - Загружает активные языки
- **Возвращает:** `view('admin.companies.create')`

#### `store(Request $request)`
- **Маршрут:** `POST /admin/companies`
- **Описание:** Создание компании
- **Валидация:**
  - `category_id`: required|exists:categories,id
  - `logo`: nullable|image|mimes:jpeg,png,jpg,gif|max:2048
  - `status`: boolean
  - `title_{lang_code}`: required для языка по умолчанию
  - `content_{lang_code}`: nullable|string
- **Логика:**
  - Загружает логотип в `public/uploads/companies/`
  - Создает запись `Company`
  - Создает связанную запись `DirectoryCompany` с контентом
- **Возвращает:** Редирект на список

#### `edit(string $id)`
- **Маршрут:** `GET /admin/companies/{id}/edit`
- **Описание:** Форма редактирования компании
- **Логика:** Загружает компанию с `directoryCompany`
- **Возвращает:** `view('admin.companies.edit')`

#### `update(Request $request, string $id)`
- **Маршрут:** `PUT /admin/companies/{id}`
- **Описание:** Обновление компании
- **Валидация:** Аналогична `store()`, плюс `delete_logo`: boolean
- **Логика:**
  - Обрабатывает удаление старого логотипа
  - Обрабатывает загрузку нового логотипа
  - Обновляет `Company` и `DirectoryCompany`
- **Возвращает:** Редирект на список

#### `destroy(string $id)`
- **Маршрут:** `DELETE /admin/companies/{id}`
- **Описание:** Удаление компании
- **Логика:** Удаляет файл логотипа перед удалением записи
- **Возвращает:** Редирект на список

---

### 8. `App\Http\Controllers\Admin\MarketplaceListingController`
**Путь:** `app/Http/Controllers/Admin/MarketplaceListingController.php`

**Методы:**

#### `index(Request $request)`
- **Маршрут:** `GET /admin/marketplace_listings`
- **Описание:** Список объявлений с фильтрацией
- **Фильтры:** Аналогичны `CompanyController`
- **Логика:** Загружает связи `category` и `listingPost`
- **Возвращает:** `view('admin.marketplace_listings.index')`

#### `create()`
- **Маршрут:** `GET /admin/marketplace_listings/create`
- **Описание:** Форма создания объявления
- **Логика:** Загружает иерархию категорий типа `marketplace`
- **Возвращает:** `view('admin.marketplace_listings.create')`

#### `store(Request $request)`
- **Маршрут:** `POST /admin/marketplace_listings`
- **Описание:** Создание объявления
- **Валидация:**
  - `category_id`: required|exists:categories,id
  - `price`: required|numeric|min:0
  - `status`: boolean
  - `title_{lang_code}`: required для языка по умолчанию
  - `description_{lang_code}`: nullable|string
- **Логика:**
  - Создает `MarketplaceListing`
  - Создает связанную запись `ListingPost` с описанием
- **Возвращает:** Редирект на список

#### `edit(string $id)`
- **Маршрут:** `GET /admin/marketplace_listings/{id}/edit`
- **Описание:** Форма редактирования объявления
- **Возвращает:** `view('admin.marketplace_listings.edit')`

#### `update(Request $request, string $id)`
- **Маршрут:** `PUT /admin/marketplace_listings/{id}`
- **Описание:** Обновление объявления
- **Логика:** Обновляет `MarketplaceListing` и `ListingPost`
- **Возвращает:** Редирект на список

#### `destroy(string $id)`
- **Маршрут:** `DELETE /admin/marketplace_listings/{id}`
- **Описание:** Удаление объявления
- **Возвращает:** Редирект на список

---

### 9. `App\Http\Controllers\Admin\RoleController`
**Путь:** `app/Http/Controllers/Admin/RoleController.php`
**Middleware:** `admin` (только для администраторов)

**Методы:**

#### `index()`
- **Маршрут:** `GET /admin/roles`
- **Описание:** Список ролей
- **Возвращает:** `view('admin.roles.index')`

#### `create()`
- **Маршрут:** `GET /admin/roles/create`
- **Описание:** Форма создания роли
- **Возвращает:** `view('admin.roles.create')`

#### `store(Request $request)`
- **Маршрут:** `POST /admin/roles`
- **Описание:** Создание роли
- **Валидация:**
  - `name`: required|string|max:255|unique:roles,name
  - `slug`: required|string|max:255|unique:roles,slug|regex:/^[a-z0-9-]+$/
  - `description`: nullable|string
- **Возвращает:** Редирект на список

#### `edit(Role $role)`
- **Маршрут:** `GET /admin/roles/{role}/edit`
- **Описание:** Форма редактирования роли
- **Возвращает:** `view('admin.roles.edit')`

#### `update(Request $request, Role $role)`
- **Маршрут:** `PUT /admin/roles/{role}`
- **Описание:** Обновление роли
- **Валидация:** Аналогична `store()`, но с проверкой уникальности
- **Возвращает:** Редирект на список

#### `destroy(Role $role)`
- **Маршрут:** `DELETE /admin/roles/{role}`
- **Описание:** Удаление роли
- **Защита:** Нельзя удалить роль, если есть пользователи с этой ролью
- **Возвращает:** Редирект на список

---

### 10. `App\Http\Controllers\Admin\UserController`
**Путь:** `app/Http/Controllers/Admin/UserController.php`
**Middleware:** `admin` (только для администраторов)

**Методы:**

#### `index()`
- **Маршрут:** `GET /admin/users`
- **Описание:** Список пользователей
- **Логика:** Загружает связи `roleRelation`
- **Возвращает:** `view('admin.users.index')`

#### `create()`
- **Маршрут:** `GET /admin/users/create`
- **Описание:** Форма создания пользователя
- **Логика:** Загружает список ролей
- **Возвращает:** `view('admin.users.create')`

#### `store(Request $request)`
- **Маршрут:** `POST /admin/users`
- **Описание:** Создание пользователя
- **Валидация:**
  - `name`: required|string|max:255
  - `email`: required|string|email|max:255|unique:users
  - `password`: required|string|min:6|confirmed
  - `role_id`: nullable|exists:roles,id
- **Логика:** Хеширует пароль через `Hash::make()`
- **Возвращает:** Редирект на список

#### `edit(User $user)`
- **Маршрут:** `GET /admin/users/{user}/edit`
- **Описание:** Форма редактирования пользователя
- **Возвращает:** `view('admin.users.edit')`

#### `update(Request $request, User $user)`
- **Маршрут:** `PUT /admin/users/{user}`
- **Описание:** Обновление пользователя
- **Валидация:**
  - `password`: nullable|string|min:6|confirmed (необязательное поле)
- **Логика:** Обновляет пароль только если он указан
- **Возвращает:** Редирект на список

#### `destroy(User $user)`
- **Маршрут:** `DELETE /admin/users/{user}`
- **Описание:** Удаление пользователя
- **Защита:** Нельзя удалить самого себя
- **Возвращает:** Редирект на список

---

### 11. `App\Http\Controllers\UserController`
**Путь:** `app/Http/Controllers/UserController.php`

**Методы:**

#### `create()`
- **Описание:** Форма создания пользователя (публичная)
- **Возвращает:** `view('user.create')`

#### `store(Request $request)`
- **Описание:** Сохранение пользователя (в разработке)
- **Логика:** Временно использует `dd($request->all())` для отладки

**Примечание:** Этот контроллер находится в разработке и предназначен для публичной регистрации пользователей.

---

## Модели (Models)

### 1. `App\Models\User`
**Путь:** `app/Models/User.php`
**Таблица:** `users`

**Поля:**
- `name` (string)
- `email` (string, unique)
- `password` (hashed)
- `role` (string, legacy)
- `role_id` (integer, foreign key → roles.id)

**Отношения:**
- `roleRelation()` → `belongsTo(Role::class)`

**Методы:**
- `hasRole($roleSlug)` - проверка наличия роли по slug
- `isAdmin()` - проверка, является ли пользователь администратором

**Особенности:**
- Поддерживает старую систему ролей (`role`) и новую (`role_id`)
- Пароль автоматически хешируется через cast

---

### 2. `App\Models\Role`
**Путь:** `app/Models/Role.php`
**Таблица:** `roles`

**Поля:**
- `name` (string)
- `slug` (string, unique, regex: `^[a-z0-9-]+$`)
- `description` (text, nullable)

**Отношения:**
- `users()` → `hasMany(User::class)`

---

### 3. `App\Models\Category`
**Путь:** `app/Models/Category.php`
**Таблица:** `categories`

**Поля:**
- `parent_id` (integer, nullable, foreign key → categories.id)
- `name` (JSON) - многоязычное название
- `slug` (string, unique)
- `type` (string) - тип категории: `directory` или `marketplace`

**Отношения:**
- `parent()` → `belongsTo(Category::class)`
- `children()` → `hasMany(Category::class)`

**Методы:**
- `getHierarchy($type, $excludeId, $depth, $prefix)` - получение иерархии категорий
- `getNameRuAttribute()`, `getNameEnAttribute()`, `getNameUaAttribute()` - аксессоры для языков

**Особенности:**
- Поддерживает иерархическую структуру (родитель-потомок)
- JSON-поле `name` хранит переводы для разных языков
- Типы категорий: `directory` (для компаний) и `marketplace` (для объявлений)

---

### 4. `App\Models\Language`
**Путь:** `app/Models/Language.php`
**Таблица:** `languages`

**Поля:**
- `code` (string, max:10, unique) - код языка (ru, en, ua)
- `name` (string) - название языка
- `is_default` (boolean) - язык по умолчанию
- `is_active` (boolean) - активен ли язык

**Отношения:**
- `categoryTranslations()` → `hasMany(CategoryTranslation::class)`
- `postTranslations()` → `hasMany(PostTranslation::class)`
- `homeTranslations()` → `hasMany(HomeTranslation::class)`

**Статические методы:**
- `getActive()` - получить все активные языки (сначала язык по умолчанию)
- `getDefault()` - получить язык по умолчанию

**Особенности:**
- Только один язык может быть языком по умолчанию
- Используется для динамической валидации и построения форм

---

### 5. `App\Models\Company`
**Путь:** `app/Models/Company.php`
**Таблица:** `companies`

**Поля:**
- `user_id` (integer, foreign key → users.id)
- `category_id` (integer, foreign key → categories.id)
- `title` (JSON) - многоязычное название
- `logo` (string, nullable) - путь к файлу логотипа
- `status` (boolean) - статус компании

**Отношения:**
- `category()` → `belongsTo(Category::class)`
- `user()` → `belongsTo(User::class)`
- `directoryCompany()` → `hasOne(DirectoryCompany::class)`

**Особенности:**
- JSON-поле `title` для многоязычности
- Логотип хранится в `public/uploads/companies/`

---

### 6. `App\Models\DirectoryCompany`
**Путь:** `app/Models/DirectoryCompany.php`
**Таблица:** `directory_companies`

**Поля:**
- `company_id` (integer, foreign key → companies.id)
- `content` (JSON) - многоязычное содержание
- `fields` (JSON, nullable) - дополнительные поля

**Отношения:**
- `company()` → `belongsTo(Company::class)`

**Особенности:**
- Связана один-к-одному с `Company`
- Хранит расширенную информацию о компании

---

### 7. `App\Models\MarketplaceListing`
**Путь:** `app/Models/MarketplaceListing.php`
**Таблица:** `marketplace_listings`

**Поля:**
- `user_id` (integer, foreign key → users.id)
- `category_id` (integer, foreign key → categories.id)
- `title` (JSON) - многоязычное название
- `price` (decimal:2) - цена
- `status` (boolean) - статус объявления

**Отношения:**
- `category()` → `belongsTo(Category::class)`
- `user()` → `belongsTo(User::class)`
- `listingPost()` → `hasOne(ListingPost::class)`

**Особенности:**
- JSON-поле `title` для многоязычности
- Цена хранится как decimal с 2 знаками после запятой

---

### 8. `App\Models\ListingPost`
**Путь:** `app/Models/ListingPost.php`
**Таблица:** `listing_posts`

**Поля:**
- `listing_id` (integer, foreign key → marketplace_listings.id)
- `description` (JSON) - многоязычное описание
- `params` (JSON, nullable) - дополнительные параметры

**Отношения:**
- `listing()` → `belongsTo(MarketplaceListing::class)`

**Особенности:**
- Связана один-к-одному с `MarketplaceListing`
- Хранит детальное описание объявления

---

### 9. `App\Models\Home`
**Путь:** `app/Models/Home.php`
**Таблица:** `homes`

**Поля:**
- `date` (date, nullable)
- `title` (JSON) - многоязычный заголовок
- `description` (JSON) - многоязычное описание

**Особенности:**
- Используется как singleton (одна запись)
- Хранит данные главной страницы сайта

---

### Другие модели

- `Block1` - блоки контента
- `CategoryTranslation` - переводы категорий (legacy)
- `Post` - посты
- `PostTranslation` - переводы постов
- `HomeTranslation` - переводы главной страницы (legacy)

---

## Архитектура и паттерны

### 1. Многоязычность

**Подход:** JSON-поля для хранения переводов

**Пример:**
```php
// В модели
protected $casts = [
    'name' => 'array',
];

// В контроллере
$nameArray = [];
foreach ($languages as $language) {
    $nameArray[$language->code] = $request->input('name_' . $language->code);
}
$model->update(['name' => $nameArray]);
```

**Преимущества:**
- Гибкость добавления новых языков
- Один запрос для получения всех переводов
- Простая валидация через динамические правила

---

### 2. Иерархические категории

**Подход:** Self-referencing через `parent_id`

**Метод получения иерархии:**
```php
Category::getHierarchy('directory', $excludeId)
```

**Использование:**
- Категории для компаний (`type = 'directory'`)
- Категории для объявлений (`type = 'marketplace'`)
- Рекурсивное построение дерева категорий

---

### 3. Система ролей

**Подход:** Отдельная таблица `roles` с связью через `role_id`

**Проверка ролей:**
```php
$user->hasRole('admin')
$user->isAdmin()
```

**Middleware:**
- `auth` - проверка авторизации
- `admin` - проверка роли администратора

---

### 4. Загрузка файлов

**Логотипы компаний:**
- Путь: `public/uploads/companies/`
- Валидация: `image|mimes:jpeg,png,jpg,gif|max:2048`
- Имя файла: `{timestamp}_{uniqid}.{extension}`

**Обработка:**
- Удаление старого файла при обновлении
- Поддержка флага `delete_logo` для удаления

---

### 5. Валидация

**Динамическая валидация:**
```php
$rules = [];
foreach ($languages as $language) {
    $fieldName = 'title_' . $language->code;
    if ($language->is_default) {
        $rules[$fieldName] = 'required|string|max:255';
    } else {
        $rules[$fieldName] = 'nullable|string|max:255';
    }
}
```

**Особенности:**
- Язык по умолчанию - обязательные поля
- Остальные языки - опциональные поля

---

### 6. Пагинация

**Стандартная пагинация:**
```php
Model::paginate(15)
```

**Использование:**
- Списки категорий, языков, компаний, объявлений, ролей, пользователей

---

### 7. Поиск и фильтрация

**Пример в CompanyController:**
```php
// Фильтр по категории
if ($request->has('category_id')) {
    $query->where('category_id', $request->category_id);
}

// Поиск по JSON-полю
if ($request->has('search')) {
    $query->whereJsonContains('title->' . $defaultLang->code, $search);
}
```

---

### 8. Сообщения об успехе/ошибке

**Формат:**
```php
return redirect()->route('admin.categories.index')
    ->with('success', 'Категория успешно создана');

return redirect()->route('admin.languages.index')
    ->with('error', 'Нельзя удалить язык по умолчанию');
```

---

## Структура файлов

```
app/
├── Http/
│   └── Controllers/
│       ├── Controller.php (базовый класс)
│       ├── UserController.php (публичный)
│       ├── Admin/
│       │   ├── MainController.php
│       │   ├── HomeController.php
│       │   ├── CategoryController.php
│       │   ├── LanguageController.php
│       │   ├── CompanyController.php
│       │   ├── MarketplaceListingController.php
│       │   ├── RoleController.php
│       │   └── UserController.php (админский)
│       └── Auth/
│           └── AdminLoginController.php
└── Models/
    ├── User.php
    ├── Role.php
    ├── Category.php
    ├── Language.php
    ├── Company.php
    ├── DirectoryCompany.php
    ├── MarketplaceListing.php
    ├── ListingPost.php
    ├── Home.php
    └── ... (другие модели)

routes/
└── web.php (все маршруты)
```

---

## Заметки для разработчиков

1. **Дублирование метода `store()` в `UserController`** - требуется исправление
2. **Middleware `admin`** - должен быть создан для защиты админских маршрутов
3. **Публичная регистрация** - `UserController` в корне находится в разработке
4. **Legacy поддержка** - модель `User` поддерживает старую систему ролей через поле `role`
5. **Singleton для Home** - используется `firstOrCreate([])` для единственной записи
6. **JSON поиск** - используется `whereJsonContains()` для поиска по многоязычным полям

---

## Заключение

Проект использует стандартные паттерны Laravel с добавлением:
- Многоязычности через JSON-поля
- Иерархических категорий
- Системы ролей
- Разделения на публичную и админскую части

Все контроллеры следуют RESTful-принципам и используют ресурсные маршруты Laravel.

