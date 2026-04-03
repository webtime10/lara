@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Новая категория</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.categories.index') }}">Категории</a></li>
                        <li class="breadcrumb-item active">Создание</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <button type="submit" form="categoryForm" class="btn btn-primary float-right" title="Сохранить" aria-label="Сохранить">
                                <i class="fas fa-save"></i> <span>Сохранить</span>
                            </button>
                        </div>
                        <div class="card-body">
                            @if ($errors->any())
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form id="categoryForm" action="{{ route('admin.categories.store') }}" method="POST">
                                @csrf

                                @if($languages->isEmpty())
                                    <p class="text-muted small mb-3">
                                        Языков пока нет — добавьте их в разделе <a href="{{ route('admin.languages.index') }}">«Языки»</a>.
                                    </p>
                                @else
                                    <ul class="nav nav-tabs" id="categoryLangTabs" role="tablist">
                                        @foreach($languages as $index => $language)
                                            <li class="nav-item">
                                                <a class="nav-link @if($index === 0) active @endif"
                                                   id="tab-lang-{{ $language->code }}"
                                                   data-toggle="tab"
                                                   href="#pane-lang-{{ $language->code }}"
                                                   role="tab"
                                                   aria-controls="pane-lang-{{ $language->code }}"
                                                   aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                                                    {{ strtolower($language->code) }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div class="tab-content border border-top-0 rounded-bottom p-3 mb-4 bg-light" id="categoryLangTabsContent">
                                        @foreach($languages as $index => $language)
                                            @php $c = $language->code; @endphp
                                            <div class="tab-pane fade @if($index === 0) show active @endif"
                                                 id="pane-lang-{{ $c }}"
                                                 role="tabpanel"
                                                 aria-labelledby="tab-lang-{{ $c }}">
                                                <div class="form-group">
                                                    <label for="name_{{ $c }}">Название категории @if($language->is_default)<span class="text-danger">*</span>@endif</label>
                                                    <input type="text" name="name_{{ $c }}" id="name_{{ $c }}"
                                                           class="form-control form-control-lg @error('name_'.$c) is-invalid @enderror"
                                                           value="{{ old('name_'.$c) }}"
                                                           placeholder="Например: Ноутбуки, Аксессуары…"
                                                           {{ $language->is_default ? 'required' : '' }}>
                                                    @error('name_'.$c)
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="form-group">
                                                    <label for="slug_{{ $c }}">Slug (ЧПУ) @if($language->is_default)<span class="text-danger">*</span>@endif</label>
                                                    <input type="text" name="slug_{{ $c }}" id="slug_{{ $c }}"
                                                           class="form-control @error('slug_'.$c) is-invalid @enderror"
                                                           value="{{ old('slug_'.$c) }}"
                                                           placeholder=""
                                                           data-slug-locked="0"
                                                           autocomplete="off">
                                                    <small class="form-text text-muted">Пустой — подставится из названия (как Str::slug в Laravel). Свой вариант — сохранится после нормализации.</small>
                                                    @error('slug_'.$c)
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="form-group">
                                                    <label for="description_{{ $c }}">Описание</label>
                                                    <textarea name="description_{{ $c }}" id="description_{{ $c }}" class="form-control" rows="4">{{ old('description_'.$c) }}</textarea>
                                                </div>
                                                <div class="form-group">
                                                    <label for="meta_title_{{ $c }}">Meta title</label>
                                                    <input type="text" name="meta_title_{{ $c }}" class="form-control" value="{{ old('meta_title_'.$c) }}">
                                                </div>
                                                <div class="form-group">
                                                    <label for="meta_description_{{ $c }}">Meta description</label>
                                                    <input type="text" name="meta_description_{{ $c }}" class="form-control" value="{{ old('meta_description_'.$c) }}">
                                                </div>
                                                <div class="form-group mb-0">
                                                    <label for="meta_keyword_{{ $c }}">Meta keyword</label>
                                                    <input type="text" name="meta_keyword_{{ $c }}" class="form-control" value="{{ old('meta_keyword_'.$c) }}">
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="form-group">
                                    <label for="parent_id">Родитель — внутри какой существующей категории</label>
                                    <select name="parent_id" id="parent_id" class="form-control @error('parent_id') is-invalid @enderror">
                                        <option value="">— В корне каталога (без родителя, верхний уровень) —</option>
                                        @foreach($parentOptions as $opt)
                                            <option value="{{ $opt['id'] }}" {{ (string) old('parent_id') === (string) $opt['id'] ? 'selected' : '' }}>
                                                {{ $opt['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="form-text text-muted">Только для вложенности; на название новой категории не влияет.</small>
                                    @error('parent_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="sort_order">Порядок сортировки</label>
                                    <input type="number" name="sort_order" id="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0" style="max-width: 12rem;">
                                </div>

                                <div class="form-group">
                                    <input type="hidden" name="status" value="0">
                                    <label><input type="checkbox" name="status" value="1" {{ old('status', true) ? 'checked' : '' }}> Активна</label>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @include('admin.partials.slug-auto-sync')
@endsection
