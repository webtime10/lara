# Что происходит при переходе на /admin

## Пошаговый процесс:

### 1. **Роутинг (routes/web.php)**
```
URL: /admin
↓
Находится роут: Route::prefix('admin')->middleware(['auth'])
↓
Проверяется middleware 'auth'
```

### 2. **Middleware 'auth' (проверка авторизации)**
```
Проверяет:
- Залогинен ли пользователь?
- Есть ли активная сессия?
↓
Если НЕТ → редирект на /login
Если ДА → продолжаем дальше
```

### 3. **Контроллер (MainController@index)**
```php
// app/Http/Controllers/Admin/MainController.php
public function index()
{
    $pageTitle = 'Admin Panel';
    return view('admin.index', compact('pageTitle'));
}
```
- Создает переменную $pageTitle
- Возвращает view 'admin.index' с этой переменной

### 4. **View (resources/views/admin/index.blade.php)**
```
@extends('admin.layouts.layout')
↓
Использует базовый layout из admin/layouts/layout.blade.php
↓
Вставляет контент в @section('content')
```

### 5. **Layout (admin/layouts/layout.blade.php)**
```
- Загружает CSS (AdminLTE, Font Awesome)
- Загружает JavaScript (jQuery, Bootstrap, Summernote)
- Отображает:
  * Header (навигация)
  * Sidebar (меню: Главная, Home, Categories, Languages, Companies, Marketplace)
  * Content (ваш контент из index.blade.php)
  * Footer
```

## Итоговый результат:

Пользователь видит админ-панель с:
- Боковым меню слева
- Навигацией сверху
- Контентом: "Добро пожаловать в админ-панель!"

## Защита:

Если пользователь НЕ залогинен:
- Middleware 'auth' перехватывает запрос
- Редирект на /login
- Пользователь не видит админку

