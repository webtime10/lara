@extends('catalog.layout')

@section('title', 'Категории промтов')

@section('content')
    <h1 style="margin-top:0;">Категории промтов</h1>
    <p class="muted">Выберите раздел, чтобы открыть список промтов на нужном языке.</p>

    @if($categories->isEmpty())
        <p>Пока нет активных категорий промтов.</p>
    @else
        <div class="grid">
            @foreach($categories as $category)
                @php $d = $category->descriptions->firstWhere('language_id', $lang->id); @endphp
                @if($d)
                    <a href="{{ route('prompt-catalog.category', ['slug' => $d->slug, 'lang' => $lang->code]) }}" class="card" style="display:block;color:inherit;">
                        <h2>{{ $d->name }}</h2>
                        @if($d->description)
                            <p class="muted" style="margin:0;">{{ \Illuminate\Support\Str::limit(strip_tags($d->description), 140) }}</p>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    @endif
@endsection
