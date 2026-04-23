@extends('catalog.layout')

@section('title', $promptDescription->name)

@section('content')
    <nav class="crumb">
        <a href="{{ route('prompt-catalog.index', ['lang' => $lang->code]) }}">Промты</a>
        @if($prompt->category)
            @php $cd = $prompt->category->descriptions->firstWhere('language_id', $lang->id); @endphp
            @if($cd)
                <span> / </span>
                <a href="{{ route('prompt-catalog.category', ['slug' => $cd->slug, 'lang' => $lang->code]) }}">{{ $cd->name }}</a>
            @endif
        @endif
    </nav>

    <article class="product-page">
        <h1>{{ $promptDescription->name }}</h1>

        @if($promptDescription->excerpt)
            <div class="product-meta">
                {!! nl2br(e($promptDescription->excerpt)) !!}
            </div>
        @endif

        <div class="product-meta">
            <h2>Текст промта</h2>
            <pre style="white-space:pre-wrap;margin:0;font:inherit;">{{ $promptDescription->prompt_text }}</pre>
        </div>
    </article>
@endsection
