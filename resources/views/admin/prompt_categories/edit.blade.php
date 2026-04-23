@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>Редактирование категории промтов</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.prompt-categories.index') }}">Категории промтов</a></li>
                        <li class="breadcrumb-item active">Редактирование</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Данные категории</h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.prompt-categories.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-reply"></i> Назад
                        </a>
                        <button type="submit" form="promptCategoryForm" class="btn btn-primary btn-sm" title="Сохранить" aria-label="Сохранить">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form id="promptCategoryForm" action="{{ route('admin.prompt-categories.update', $category->id) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <ul class="nav nav-tabs" role="tablist">
                            @foreach($languages as $index => $language)
                                <li class="nav-item">
                                    <a class="nav-link {{ $index === 0 ? 'active' : '' }}" data-toggle="tab" href="#lang-cat-{{ $language->id }}">{{ strtolower($language->code) }}</a>
                                </li>
                            @endforeach
                        </ul>

                        <div class="tab-content border border-top-0 p-3 mb-4">
                            @foreach($languages as $index => $language)
                                @php
                                    $c = $language->code;
                                    $desc = $category->descriptions->firstWhere('language_id', $language->id);
                                @endphp
                                <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="lang-cat-{{ $language->id }}">
                                    <div class="form-group">
                                        <label for="name_{{ $c }}">Название @if($language->is_default)<span class="text-danger">*</span>@endif</label>
                                        <input type="text" name="name_{{ $c }}" id="name_{{ $c }}" class="form-control @error('name_'.$c) is-invalid @enderror" value="{{ old('name_'.$c, $desc->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
                                        @error('name_'.$c)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="description_{{ $c }}">Описание</label>
                                        <textarea name="description_{{ $c }}" id="description_{{ $c }}" class="form-control" rows="4">{{ old('description_'.$c, $desc->description ?? '') }}</textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="stage_2_live_{{ $c }}">Stage 2 live</label>
                                        <textarea name="stage_2_live_{{ $c }}" id="stage_2_live_{{ $c }}" class="form-control" rows="8">{{ old('stage_2_live_'.$c, $desc?->stage_2_live ?? '') }}</textarea>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="stage_3_edit_{{ $c }}">Stage 3 edit</label>
                                        <textarea name="stage_3_edit_{{ $c }}" id="stage_3_edit_{{ $c }}" class="form-control" rows="8">{{ old('stage_3_edit_'.$c, $desc?->stage_3_edit ?? '') }}</textarea>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="form-group">
                            <label for="stage_1_extraction">Stage 1 extraction</label>
                            <textarea name="stage_1_extraction" id="stage_1_extraction" class="form-control" rows="8">{{ old('stage_1_extraction', $category->stage_1_extraction) }}</textarea>
                        </div>

                        <div class="form-group d-none">
                            <label for="parent_id">Родительская категория</label>
                            <select name="parent_id" id="parent_id" class="form-control">
                                <option value="">— В корне —</option>
                                @foreach($parentOptions as $option)
                                    <option value="{{ $option['id'] }}" {{ (string) old('parent_id', $category->parent_id) === (string) $option['id'] ? 'selected' : '' }}>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="manufacturer_id">Сайт</label>
                            <select name="manufacturer_id" id="manufacturer_id" class="form-control">
                                <option value="">— Не выбран —</option>
                                @foreach($manufacturers as $manufacturer)
                                    <option value="{{ $manufacturer->id }}" {{ (string) old('manufacturer_id', $category->manufacturer_id) === (string) $manufacturer->id ? 'selected' : '' }}>
                                        {{ $manufacturer->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="ai_field">AI поле</label>
                            <select name="ai_field" id="ai_field" class="form-control @error('ai_field') is-invalid @enderror">
                                <option value="">— Не выбрано —</option>
                                @foreach($aiFieldOptions as $fieldKey => $fieldLabel)
                                    <option value="{{ $fieldKey }}" {{ old('ai_field', $category->ai_field) === $fieldKey ? 'selected' : '' }}>
                                        {{ $fieldLabel }} ({{ $fieldKey }})
                                    </option>
                                @endforeach
                            </select>
                            @error('ai_field')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="form-group">
                            <div class="d-flex align-items-center justify-content-between">
                                <label for="row_data" class="mb-0">Нотация к сырью</label>
                                <button type="submit" form="promptCategoryForm" class="btn btn-primary btn-sm" title="Сохранить" aria-label="Сохранить">
                                    <i class="fas fa-save"></i>
                                </button>
                            </div>
                            <textarea name="row_data" id="row_data" class="form-control @error('row_data') is-invalid @enderror mt-2" rows="12">{{ old('row_data', $category->row_data) }}</textarea>
                            @error('row_data')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="form-group">
                            <label for="sort_order">Порядок сортировки</label>
                            <input type="number" name="sort_order" id="sort_order" class="form-control" value="{{ old('sort_order', $category->sort_order) }}" min="0" style="max-width: 12rem;">
                        </div>

                        <div class="form-group mb-0">
                            <input type="hidden" name="status" value="0">
                            <label><input type="checkbox" name="status" value="1" {{ old('status', $category->status) ? 'checked' : '' }}> Активна</label>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
