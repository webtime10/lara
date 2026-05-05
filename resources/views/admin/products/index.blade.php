@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item active">Посты</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <a href="{{ route('admin.products.create') }}" class="btn btn-primary float-right">Добавить пост</a>
                    <form id="bulk-delete-form" action="{{ route('admin.products.bulk_destroy') }}" method="post" class="d-inline-block mr-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" id="bulk-delete-btn" class="btn btn-danger" disabled>Удалить выбранные</button>
                    </form>
                </div>
                <div class="card-body border-bottom">
                    <form method="GET" action="{{ route('admin.products.index') }}">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group mb-2">
                                    <label for="author">Автор</label>
                                    <select name="author" id="author" class="form-control">
                                        <option value="">Все авторы</option>
                                        @foreach($authors as $author)
                                            <option value="{{ $author->id }}" {{ (string)$selectedAuthor === (string)$author->id ? 'selected' : '' }}>
                                                {{ $author->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-2">
                                    <label for="category">Категория</label>
                                    <select name="category" id="category" class="form-control">
                                        <option value="">Все категории</option>
                                        @foreach($categories as $category)
                                            @php $cd = $defaultLanguage ? $category->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                                            @if($cd)
                                                <option value="{{ $category->id }}" {{ (string)$selectedCategory === (string)$category->id ? 'selected' : '' }}>
                                                    {{ $cd->name }}
                                                </option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-2">
                                    <label for="month">Месяц</label>
                                    <select name="month" id="month" class="form-control">
                                        <option value="">Все месяцы</option>
                                        @foreach($months as $month)
                                            <option value="{{ $month }}" {{ (string)$selectedMonth === (string)$month ? 'selected' : '' }}>
                                                {{ $month }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-2">
                                    <label for="manufacturer">Сайт</label>
                                    <select name="manufacturer" id="manufacturer" class="form-control">
                                        <option value="">Все сайты</option>
                                        @foreach($manufacturers as $manufacturer)
                                            <option value="{{ $manufacturer->id }}" {{ (string)$selectedManufacturer === (string)$manufacturer->id ? 'selected' : '' }}>
                                                {{ $manufacturer->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Фильтровать</button>
                        <a href="{{ route('admin.products.index') }}" class="btn btn-sm btn-secondary">Сбросить</a>
                    </form>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="check-all-products" title="Выбрать все">
                                </th>
                                <th>Model</th>
                                <th>Название</th>
                                <th>Сайт</th>
                                <th>Категория</th>
                                <th>Статус</th>
                                <th>Автор</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $p)
                                @php
                                    $pd = $defaultLanguage ? $p->descriptions->firstWhere('language_id', $defaultLanguage->id) : null;
                                    $firstCategory = $p->categories->first();
                                    $firstCategoryDescription = $defaultLanguage && $firstCategory
                                        ? $firstCategory->descriptions->firstWhere('language_id', $defaultLanguage->id)
                                        : null;
                                    $authorLabel = $p->author?->name ?? '—';
                                    if ($p->author) {
                                        $roleLabel = trim((string) ($p->author->role ?? ''));
                                        if ($roleLabel !== '') {
                                            $authorLabel .= ' ('.$roleLabel.')';
                                        }
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="ids[]" value="{{ $p->id }}" class="product-checkbox" form="bulk-delete-form">
                                    </td>
                                    <td>{{ $p->model }}</td>
                                    <td>{{ $pd->name ?? '—' }}</td>
                                    <td>{{ $p->manufacturer->name ?? '—' }}</td>
                                    <td>{{ $firstCategoryDescription->name ?? '—' }}</td>
                                    <td>{{ $p->status ? 'Да' : 'Нет' }}</td>
                                    <td>{{ $authorLabel }}</td>
                                    <td>
                                        <a href="{{ route('admin.products.edit', $p->id) }}" class="btn btn-sm btn-info">Изм.</a>
                                        <form action="{{ route('admin.products.destroy', $p->id) }}" method="post" class="d-inline" onsubmit="return confirm('Удалить?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Удал.</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center">Нет постов</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Page Navigation</span>
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </section>
    <script>
        (function () {
            var checkAll = document.getElementById('check-all-products');
            var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.product-checkbox'));
            var deleteBtn = document.getElementById('bulk-delete-btn');
            var deleteForm = document.getElementById('bulk-delete-form');

            function syncState() {
                var checkedCount = checkboxes.filter(function (cb) { return cb.checked; }).length;
                deleteBtn.disabled = checkedCount === 0;
                if (checkAll) {
                    checkAll.checked = checkedCount > 0 && checkedCount === checkboxes.length;
                    checkAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
                }
            }

            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    checkboxes.forEach(function (cb) { cb.checked = checkAll.checked; });
                    syncState();
                });
            }

            checkboxes.forEach(function (cb) {
                cb.addEventListener('change', syncState);
            });

            if (deleteForm) {
                deleteForm.addEventListener('submit', function (event) {
                    if (!confirm('Удалить выбранные посты?')) {
                        event.preventDefault();
                    }
                });
            }

            syncState();
        })();
    </script>
@endsection
