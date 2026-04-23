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
        @php($aiFields = \App\Models\ProductDescription::aiFieldLabels())
        @foreach($aiFields as $fieldKey => $fieldLabel)
            @if(!empty($prodDesc->{$fieldKey}))
                <div class="product-meta product-ai-field product-ai-field-{{ $fieldKey }}">
                    <h2>{{ $fieldLabel }}</h2>
                    {!! $prodDesc->aiStructuredFieldHtml($fieldKey) !!}
                </div>
            @endif
        @endforeach
    </article>
@endsection
