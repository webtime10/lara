<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminLoginController extends Controller
{
    // Показать форму входа
    public function showLoginForm()
    {
        return view('auth.login'); 
    }

    // Обработка логина
    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'], // Email или имя
            'password' => ['required'],
        ]);

        try {
            $login = $request->input('login');
            $password = $request->input('password');
            
            // Определяем, что введено: email или имя
            $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';
            
            // Пытаемся найти пользователя по email или name
            $user = \App\Models\User::where($field, $login)->first();
            
            // Проверяем пароль
            if ($user && Hash::check($password, $user->password)) {
                // Авторизуем пользователя
                Auth::login($user);
                $request->session()->regenerate();
                
                // Проверяем, что у пользователя есть роль: role_id или строка role
                $user->load('roleRelation');
                $hasRole = ! empty($user->role_id) || filled($user->getAttribute('role'));
                
                if ($hasRole) {
                    return redirect()->intended('/admin'); // Пускаем в админку
                }

                // Если у пользователя нет роли — вылогиниваем его
                Auth::logout();
                return back()->withErrors(['login' => 'У пользователя не назначена роль. Обратитесь к администратору.']);
            }

            return back()->withErrors(['login' => 'Неверный логин или пароль.']);
            
        } catch (\Exception $e) {
            // Логируем ошибку для отладки
            \Log::error('Login error: ' . $e->getMessage());
            
            return back()->withErrors(['login' => 'Произошла ошибка при входе. Проверьте правильность данных.']);
        }
    }

    // Выход
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
