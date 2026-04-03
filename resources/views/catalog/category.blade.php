@extends('catalog.layout')

@section('title', $catDesc->name)

@section('content')
    <nav class="crumb">
        <a href="{{ route('catalog.index') }}">Главная</a>
        @foreach($breadcrumb as $i => $item)
            <span> / </span>
            @if($i < count($breadcrumb) - 1 && !empty($item['slug']))
                <a href="{{ route('catalog.category', $item['slug']) }}">{{ $item['name'] }}</a>
            @else
                <span>{{ $item['name'] }}</span>
            @endif
        @endforeach
    </nav>

    <h1 style="margin-top:0;">{{ $catDesc->name }}</h1>
    @if($catDesc->description)
        <div class="product-meta">{!! $catDesc->description !!}</div>
    @endif

    @if($children->isNotEmpty())
        <h2>Подкатегории</h2>
        <div class="grid" style="margin-bottom:2rem;">
            @foreach($children as $child)
                @php $cd = $child->descriptions->firstWhere('language_id', $lang->id); @endphp
                @if($cd)
                    <a href="{{ route('catalog.category', $cd->slug) }}" class="card" style="display:block;color:inherit;">
                        <h3>{{ $cd->name }}</h3>
                    </a>
                @endif
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('catalog.category', $catDesc->slug) }}" style="margin: 1rem 0 1.5rem 0;">
        <div class="grid" style="grid-template-columns: repeat(4, minmax(180px, 1fr)); gap: 10px;">
            <div>
                <label for="author" class="muted">Автор</label>
                <select name="author" id="author" class="input">
                    <option value="">Все авторы</option>
                    @foreach($authors as $author)
                        <option value="{{ $author->id }}" {{ (string)$selectedAuthor === (string)$author->id ? 'selected' : '' }}>
                            {{ $author->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="category" class="muted">Категория</label>
                <select name="category" id="category" class="input">
                    <option value="">Все категории</option>
                    @foreach($filterCategories as $filterCategory)
                        @php $fcd = $filterCategory->descriptions->firstWhere('language_id', $lang->id); @endphp
                        @if($fcd)
                            <option value="{{ $filterCategory->id }}" {{ (string)$selectedCategory === (string)$filterCategory->id ? 'selected' : '' }}>
                                {{ $fcd->name }}
                            </option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label for="month" class="muted">Месяц</label>
                <select name="month" id="month" class="input">
                    <option value="">Все месяцы</option>
                    @foreach($months as $month)
                        <option value="{{ $month }}" {{ (string)$selectedMonth === (string)$month ? 'selected' : '' }}>
                            {{ $month }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="manufacturer" class="muted">Производитель</label>
                <select name="manufacturer" id="manufacturer" class="input">
                    <option value="">Все производители</option>
                    @foreach($manufacturers as $manufacturer)
                        <option value="{{ $manufacturer->id }}" {{ (string)$selectedManufacturer === (string)$manufacturer->id ? 'selected' : '' }}>
                            {{ $manufacturer->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div style="margin-top:10px;">
            <button type="submit">Фильтровать</button>
            <a href="{{ route('catalog.category', $catDesc->slug) }}" class="muted" style="margin-left:10px;">Сбросить</a>
        </div>
    </form>

    <h2>Товары</h2>
    @if($products->isEmpty())
        <p class="muted">В этой категории пока нет товаров. Добавьте товар в админке и привяжите к категории.</p>
    @else
        <div class="grid">
            @foreach($products as $product)
                @php
                    $pd = $product->descriptions->firstWhere('language_id', $lang->id);
                @endphp
                @if($pd)
                    <a href="{{ route('catalog.product', $pd->slug) }}" class="card" style="display:block;color:inherit;">
                        <h3>{{ $pd->name }}</h3>
                        @if($product->author)
                            <p class="muted" style="margin:0;">Автор: {{ $product->author->name }}</p>
                        @endif
                        <p class="muted" style="margin:0;">Дата: {{ optional($product->created_at)->format('Y-m-d') }}</p>
                        @if($product->manufacturer)
                            <p class="muted" style="margin:0;">{{ $product->manufacturer->name }}</p>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    @endif
@endsection
