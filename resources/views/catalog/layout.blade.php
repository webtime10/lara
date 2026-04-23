<!DOCTYPE html>
<html lang="{{ $lang->code ?? 'ru' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Каталог') — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f6f9; color: #222; line-height: 1.5; }
        a { color: #0d6efd; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1rem 1.25rem 2rem; }
        header.site { background: #fff; border-bottom: 1px solid #dee2e6; margin-bottom: 1.5rem; }
        header.site .inner { max-width: 1100px; margin: 0 auto; padding: 0.75rem 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        header.site h1 { margin: 0; font-size: 1.25rem; }
        .crumb { font-size: 0.9rem; color: #555; margin-bottom: 1rem; }
        .crumb a { color: #555; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; }
        .card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 1rem; transition: box-shadow .15s; }
        .card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        .card h2, .card h3 { margin: 0 0 .5rem; font-size: 1.05rem; }
        .price { font-weight: 600; color: #198754; font-size: 1.1rem; }
        .muted { color: #6c757d; font-size: .9rem; }
        .product-page h1 { margin-top: 0; }
        .product-meta { margin: 1rem 0; padding: 1rem; background: #fff; border-radius: 8px; border: 1px solid #dee2e6; }
        footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #dee2e6; font-size: .85rem; color: #888; }
    </style>
    @stack('head')
</head>
<body>
<header class="site">
    <div class="inner">
        <h1><a href="{{ route('catalog.index') }}" style="color:inherit;">Каталог</a></h1>
        <nav>
            <a href="{{ route('catalog.index') }}">Главная</a>
            <span class="muted"> · </span>
            <a href="{{ route('prompt-catalog.index', ['lang' => $lang->code ?? null]) }}">Промты</a>
            @auth
                <span class="muted"> · </span>
                <a href="{{ route('admin.index') }}">Админка</a>
            @else
                <span class="muted"> · </span>
                <a href="{{ route('login') }}">Вход</a>
            @endauth
            @if(!empty($availableLanguages) && count($availableLanguages) > 1)
                <span class="muted"> · </span>
                @foreach($availableLanguages as $availableLanguage)
                    <a href="{{ request()->fullUrlWithQuery(['lang' => $availableLanguage->code]) }}">{{ strtoupper($availableLanguage->code) }}</a>@if(!$loop->last)<span class="muted"> / </span>@endif
                @endforeach
            @endif
        </nav>
    </div>
</header>
<div class="wrap">
    @yield('content')
    <footer>
        {{ config('app.name') }}
    </footer>
</div>
</body>
</html>
