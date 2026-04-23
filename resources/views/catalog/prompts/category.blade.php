@extends('catalog.layout')

@section('title', $categoryDescription->name)

@section('content')
    <nav class="crumb">
        <a href="{{ route('prompt-catalog.index', ['lang' => $lang->code]) }}">Промты</a>
        @foreach($breadcrumb as $item)
            <span> / </span>
            @if(!empty($item['slug']))
                <a href="{{ route('prompt-catalog.category', ['slug' => $item['slug'], 'lang' => $lang->code]) }}">{{ $item['name'] }}</a>
            @else
                <span>{{ $item['name'] }}</span>
            @endif
        @endforeach
    </nav>

    <h1 style="margin-top:0;">{{ $categoryDescription->name }}</h1>

    @if($categoryDescription->description)
        <div class="product-meta">{!! $categoryDescription->description !!}</div>
    @endif

    @if($children->isNotEmpty())
        <h2>Подкатегории</h2>
        <div class="grid" style="margin-bottom:2rem;">
            @foreach($children as $child)
                @php $d = $child->descriptions->firstWhere('language_id', $lang->id); @endphp
                @if($d)
                    <a href="{{ route('prompt-catalog.category', ['slug' => $d->slug, 'lang' => $lang->code]) }}" class="card" style="display:block;color:inherit;">
                        <h3>{{ $d->name }}</h3>
                    </a>
                @endif
            @endforeach
        </div>
    @endif

    <h2>Промты</h2>
    @if($prompts->isEmpty())
        <p class="muted">В этой категории пока нет промтов.</p>
    @else
        <div class="grid">
            @foreach($prompts as $prompt)
                @php $d = $prompt->descriptions->firstWhere('language_id', $lang->id); @endphp
                @if($d)
                    <a href="{{ route('prompt-catalog.prompt', ['slug' => $d->slug, 'lang' => $lang->code]) }}" class="card" style="display:block;color:inherit;">
                        <h3>{{ $d->name }}</h3>
                        @if($d->excerpt)
                            <p class="muted" style="margin:0;">{{ \Illuminate\Support\Str::limit(strip_tags($d->excerpt), 180) }}</p>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    @endif
@endsection
