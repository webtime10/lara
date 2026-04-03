@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Languages - Редактирование</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.languages.index') }}">Languages</a></li>
                        <li class="breadcrumb-item active">Редактирование</li>
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
                            <button type="submit" form="languageForm" class="btn btn-primary float-right" title="Сохранить">
                                <i class="fa fa-save"></i>
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

                            <form id="languageForm" action="{{ route('admin.languages.update', $language->id) }}" method="POST">
                                @csrf
                                @method('PUT')

                                <div class="form-group">
                                    <label for="code">Код языка <span class="text-danger">*</span></label>
                                    <input type="text" name="code" id="code" class="form-control @error('code') is-invalid @enderror" 
                                           value="{{ old('code', $language->code) }}" placeholder="ru, en, uk, de..." required maxlength="10">
                                    <small class="form-text text-muted">Двухбуквенный код языка (ISO 639-1)</small>
                                    @error('code')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="name">Название языка <span class="text-danger">*</span></label>
                                    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" 
                                           value="{{ old('name', $language->name) }}" placeholder="Русский, English, Українська..." required maxlength="100">
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="locale">Locale <span class="text-danger">*</span></label>
                                    <input type="text" name="locale" id="locale" class="form-control @error('locale') is-invalid @enderror"
                                           value="{{ old('locale', $language->locale ?? '') }}" required maxlength="255">
                                    @error('locale')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="directory">Directory (OpenCart)</label>
                                    <input type="text" name="directory" id="directory" class="form-control @error('directory') is-invalid @enderror"
                                           value="{{ old('directory', $language->directory) }}" maxlength="32">
                                    @error('directory')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="sort_order">Порядок</label>
                                    <input type="number" name="sort_order" id="sort_order" class="form-control"
                                           value="{{ old('sort_order', $language->sort_order) }}" min="0">
                                </div>

                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="is_default" value="1" {{ old('is_default', $language->is_default) ? 'checked' : '' }}>
                                        Язык по умолчанию
                                    </label>
                                    <small class="form-text text-muted d-block">Если отмечено, этот язык будет использоваться по умолчанию. Текущий язык по умолчанию будет снят.</small>
                                </div>

                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $language->is_active) ? 'checked' : '' }}>
                                        Активен
                                    </label>
                                    <small class="form-text text-muted d-block">Только активные языки будут отображаться в формах категорий и Home.</small>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
