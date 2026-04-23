@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
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
                    <a href="{{ route('admin.prompts.create') }}" class="btn btn-primary float-right">Добавить промт</a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Slug</th>
                                <th>Категория</th>
                                <th>Порядок</th>
                                <th>Статус</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($prompts as $prompt)
                                @php
                                    $defId = $defaultLanguage?->id;
                                    $d = $defId ? $prompt->descriptions->firstWhere('language_id', $defId) : null;
                                    $cd = $prompt->category && $defId ? $prompt->category->descriptions->firstWhere('language_id', $defId) : null;
                                @endphp
                                <tr>
                                    <td>{{ $prompt->id }}</td>
                                    <td>{{ $d->name ?? '—' }}</td>
                                    <td>{{ $d->slug ?? '—' }}</td>
                                    <td>{{ $cd->name ?? '—' }}</td>
                                    <td>{{ $prompt->sort_order }}</td>
                                    <td>{{ $prompt->status ? 'Да' : 'Нет' }}</td>
                                    <td>
                                        <a href="{{ route('admin.prompts.edit', $prompt->id) }}" class="btn btn-sm btn-info">Изм.</a>
                                        <form action="{{ route('admin.prompts.destroy', $prompt->id) }}" method="post" class="d-inline" onsubmit="return confirm('Удалить промт?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Удал.</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center">Нет промтов</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $prompts->links() }}</div>
            </div>
        </div>
    </section>
@endsection
