@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Посты</a></li>
                        <li class="breadcrumb-item active">Создание</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Данные поста</h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.products.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-reply"></i> Назад
                        </a>
                        <button type="submit" form="productForm" class="btn btn-primary btn-sm" title="Сохранить" aria-label="Сохранить">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
                </div>
                <form id="productForm" action="{{ route('admin.products.store') }}" method="post" novalidate>
                    @csrf
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger mb-3">{{ $errors->first() }}</div>
                        @endif
                        @if($categories->isEmpty())
                            <p class="text-muted small mb-3">Категорий пока нет — можно <a href="{{ route('admin.categories.create') }}">создать категорию</a>, чтобы привязать пост.</p>
                        @endif

                        <div class="form-group">
                            <label for="category_ids">Категория <span class="text-danger">*</span></label>
                            <select name="category_ids[]" id="category_ids" class="form-control" required>
                                <option value="">— Выберите категорию —</option>
                                @foreach($categories as $cat)
                                    @php $d = $defaultLanguage ? $cat->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                                    <option value="{{ $cat->id }}" {{ in_array($cat->id, old('category_ids', [])) ? 'selected' : '' }}>
                                        {{ $d->name ?? '#'.$cat->id }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="model">Model (артикул) <span class="text-danger">*</span></label>
                                    <input type="text" name="model" id="model" class="form-control" value="{{ $nextModel }}" required maxlength="64" autocomplete="off">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="manufacturer_id">Сайт <span class="text-danger">*</span></label>
                                    <select name="manufacturer_id" id="manufacturer_id" class="form-control" required>
                                        <option value="">—</option>
                                        @foreach($manufacturers as $m)
                                            <option value="{{ $m->id }}" {{ old('manufacturer_id') == $m->id ? 'selected' : '' }}>{{ $m->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        {{-- Цена / Количество / Порядок скрыты из формы. Колонки сохранены в БД. --}}
                        {{-- Картинка (путь) скрыта из формы. Колонка сохранена в БД. --}}

                        <h5>Описания по языкам</h5>
                        <ul class="nav nav-tabs" role="tablist">
                            @foreach($languages as $i => $language)
                                <li class="nav-item">
                                    <a class="nav-link {{ $i === 0 ? 'active' : '' }}" data-toggle="tab" href="#lang{{ $language->id }}">{{ $language->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="tab-content border p-3">
                            @foreach($languages as $i => $language)
                                @php $c = $language->code; @endphp
                                <div class="tab-pane fade {{ $i === 0 ? 'show active' : '' }}" id="lang{{ $language->id }}">
                                    <div class="form-group">
                                        <label for="name_{{ $c }}">Название <span class="text-danger">*</span></label>
                                        <input type="text" name="name_{{ $c }}" id="name_{{ $c }}" class="form-control" value="{{ old('name_'.$c) }}" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="slug_{{ $c }}">Slug <span class="text-danger">*</span></label>
                                        <input type="text" name="slug_{{ $c }}" id="slug_{{ $c }}" class="form-control" value="{{ old('slug_'.$c) }}" data-slug-locked="0" autocomplete="off" required>
                                        <small class="form-text text-muted">Пустой — из названия (Str::slug). Свой текст — после нормализации.</small>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="form-group mt-3">
                            <input type="hidden" name="status" value="0">
                            <label><input type="checkbox" name="status" value="1" {{ old('status', true) ? 'checked' : '' }}> Активен</label>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
    @include('admin.partials.slug-auto-sync')
@endsection
