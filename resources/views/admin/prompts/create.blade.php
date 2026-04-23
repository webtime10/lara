@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>Новый промт</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.prompts.index') }}">Промты</a></li>
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
                    <button type="submit" form="promptForm" class="btn btn-primary float-right">Сохранить</button>
                </div>
                <div class="card-body">
                    <form id="promptForm" action="{{ route('admin.prompts.store') }}" method="POST">
                        @csrf

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="prompt_category_id">Категория промта</label>
                                    <select name="prompt_category_id" id="prompt_category_id" class="form-control">
                                        <option value="">— Без категории —</option>
                                        @foreach($categories as $category)
                                            @php $d = $defaultLanguage ? $category->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                                            <option value="{{ $category->id }}" {{ (string) old('prompt_category_id') === (string) $category->id ? 'selected' : '' }}>
                                                {{ $d->name ?? '#'.$category->id }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="sort_order">Порядок сортировки</label>
                                    <input type="number" name="sort_order" id="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group pt-4">
                                    <input type="hidden" name="status" value="0">
                                    <label><input type="checkbox" name="status" value="1" {{ old('status', true) ? 'checked' : '' }}> Активен</label>
                                </div>
                            </div>
                        </div>

                        <ul class="nav nav-tabs" role="tablist">
                            @foreach($languages as $index => $language)
                                <li class="nav-item">
                                    <a class="nav-link {{ $index === 0 ? 'active' : '' }}" data-toggle="tab" href="#lang-prompt-{{ $language->id }}">{{ strtolower($language->code) }}</a>
                                </li>
                            @endforeach
                        </ul>

                        <div class="tab-content border border-top-0 p-3">
                            @foreach($languages as $index => $language)
                                @php $c = $language->code; @endphp
                                <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="lang-prompt-{{ $language->id }}">
                                    <div class="form-group">
                                        <label for="name_{{ $c }}">Название @if($language->is_default)<span class="text-danger">*</span>@endif</label>
                                        <input type="text" name="name_{{ $c }}" id="name_{{ $c }}" class="form-control @error('name_'.$c) is-invalid @enderror" value="{{ old('name_'.$c) }}" {{ $language->is_default ? 'required' : '' }}>
                                        @error('name_'.$c)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="slug_{{ $c }}">Slug @if($language->is_default)<span class="text-danger">*</span>@endif</label>
                                        <input type="text" name="slug_{{ $c }}" id="slug_{{ $c }}" class="form-control @error('slug_'.$c) is-invalid @enderror" value="{{ old('slug_'.$c) }}" data-slug-locked="0" autocomplete="off">
                                        @error('slug_'.$c)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="form-group">
                                        <label for="excerpt_{{ $c }}">Короткое описание</label>
                                        <textarea name="excerpt_{{ $c }}" id="excerpt_{{ $c }}" class="form-control" rows="3">{{ old('excerpt_'.$c) }}</textarea>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="prompt_text_{{ $c }}">Текст промта @if($language->is_default)<span class="text-danger">*</span>@endif</label>
                                        <textarea name="prompt_text_{{ $c }}" id="prompt_text_{{ $c }}" class="form-control @error('prompt_text_'.$c) is-invalid @enderror" rows="10">{{ old('prompt_text_'.$c) }}</textarea>
                                        @error('prompt_text_'.$c)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    @include('admin.partials.slug-auto-sync')
@endsection
