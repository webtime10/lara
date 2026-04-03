<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MainController extends Controller
{
    /**
     * Главная страница админ‑панели.
     *
     * Просто отдаёт шаблон admin.index с заголовком страницы.
     * К очередям/Redis это не относится, но служит входной точкой в административный интерфейс.
     */
    public function index()
    {
        $pageTitle = 'Admin Panel';
        return view('admin.index', compact('pageTitle'));
    }
}
