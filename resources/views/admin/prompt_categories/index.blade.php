@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>{{ $pageTitle }}</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item active">Промты</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <a href="{{ route('admin.prompt-categories.create') }}" class="btn btn-primary float-right">Добавить промпт</a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Сайт</th>
                                <th>AI поле</th>
                                <th>Порядок</th>
                                <th>Статус</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($categories as $item)
                                @php
                                    $defId = $defaultLanguage?->id;
                                    $d = $defId ? $item->descriptions->firstWhere('language_id', $defId) : null;
                                    $parent = $item->parent && $defId ? $item->parent->descriptions->firstWhere('language_id', $defId) : null;
                                @endphp
                                <tr>
                                    <td>{{ $item->id }}</td>
                                    <td>{{ $d->name ?? '—' }}</td>
                                    <td>{{ $item->manufacturer?->name ?? '—' }}</td>
                                    <td>{{ $item->ai_field ? ($aiFieldOptions[$item->ai_field] ?? $item->ai_field) : '—' }}</td>
                                    <td>{{ $item->sort_order }}</td>
                                    <td>{{ $item->status ? 'Да' : 'Нет' }}</td>
                                    <td>
                                        <a href="{{ route('admin.prompt-categories.edit', $item->id) }}" class="btn btn-sm btn-info">Изм.</a>
                                        <form action="{{ route('admin.prompt-categories.destroy', $item->id) }}" method="post" class="d-inline" onsubmit="return confirm('Удалить промпт?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Удал.</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center">Нет промптов</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $categories->links() }}</div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Промт для выжимки</h3>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.prompt-categories.update-extraction-prompt') }}" method="post">
                        @csrf
                        <div class="form-group mb-2">
                            <textarea
                                name="prompt_text"
                                class="form-control @error('prompt_text') is-invalid @enderror"
                                rows="10"
                                placeholder="Промт для выжимки"
                            >{{ old('prompt_text', $extractionPrompt?->prompt_text ?? '') }}</textarea>
                            @error('prompt_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" title="Сохранить" aria-label="Сохранить">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
