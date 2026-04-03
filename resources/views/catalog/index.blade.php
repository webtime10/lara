@extends('catalog.layout')

@section('title', 'Главная')

@section('content')
    <h1 style="margin-top:0;">Категории</h1>
    <p class="muted">Выберите раздел — внутри будут подкатегории и товары (как в OpenCart).</p>

    @if($categories->isEmpty())
        <p>Пока нет активных категорий. Добавьте их в <a href="{{ route('admin.categories.index') }}">админке</a>.</p>
    @else
        <div class="grid">
            @foreach($categories as $cat)
                @php $d = $cat->descriptions->firstWhere('language_id', $lang->id); @endphp
                @if($d)
                    <a href="{{ route('catalog.category', $d->slug) }}" class="card" style="display:block;color:inherit;">
                        <h2>{{ $d->name }}</h2>
                        @if($d->description)
                            <p class="muted" style="margin:0;font-size:.9rem;">{{ \Illuminate\Support\Str::limit(strip_tags($d->description), 120) }}</p>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    @endif
@endsection
