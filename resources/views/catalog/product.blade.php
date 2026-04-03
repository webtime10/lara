@extends('catalog.layout')

@section('title', $prodDesc->name)

@section('content')
    <nav class="crumb">
        <a href="{{ route('catalog.index') }}">Главная</a>
        @foreach($product->categories as $cat)
            @php $cd = $cat->descriptions->firstWhere('language_id', $lang->id); @endphp
            @if($cd)
                <span> / </span>
                <a href="{{ route('catalog.category', $cd->slug) }}">{{ $cd->name }}</a>
            @endif
        @endforeach
    </nav>

    <article class="product-page">
        <h1>{{ $prodDesc->name }}</h1>
        @if($product->manufacturer)
            <p class="muted">Производитель: {{ $product->manufacturer->name }}</p>
        @endif
        @if($product->author)
            <p class="muted">Автор: {{ $product->author->name }}</p>
        @endif
        <p class="muted">Опубликовано: {{ optional($product->created_at)->format('Y-m-d') }}</p>
        <p class="muted">Артикул (model): {{ $product->model }}</p>

        @if($prodDesc->description)
            <div class="product-meta">
                {!! $prodDesc->description !!}
            </div>
        @endif
        @if(!empty($prodDesc->ai_text_about_the_country))
            <div class="product-meta product-about-country">
                {!! $prodDesc->aiStructuredFieldHtml('ai_text_about_the_country') !!}
            </div>
        @endif
        @if(!empty($prodDesc->ai_reviews_from_tourists))
            <div class="product-meta product-tourist-reviews">
                <h2>Отзывы туристов</h2>
                {!! $prodDesc->aiStructuredFieldHtml('ai_reviews_from_tourists') !!}
            </div>
        @endif
    </article>
@endsection
