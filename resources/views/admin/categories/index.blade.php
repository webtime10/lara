@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Categories - Список</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item active">Categories</li>
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
                            <a href="{{ route('admin.categories.create') }}" class="btn btn-primary float-right">
                                <i class="fa fa-plus"></i>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">#</th>
                                            <th>Slug</th>
                                            <th>Название</th>
                                            <th>Порядок</th>
                                            <th>Статус</th>
                                            <th>Родитель</th>
                                            <th style="width: 150px">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($categories as $item)
                                            @php
                                                $defId = $defaultLanguage?->id;
                                                $d = $defId ? $item->descriptions->firstWhere('language_id', $defId) : null;
                                                $pd = $item->parent && $defId
                                                    ? $item->parent->descriptions->firstWhere('language_id', $defId)
                                                    : null;
                                            @endphp
                                            <tr>
                                                <td>{{ $loop->iteration }}</td>
                                                <td>{{ $d->slug ?? '—' }}</td>
                                                <td>{{ $d->name ?? '—' }}</td>
                                                <td>{{ $item->sort_order }}</td>
                                                <td>{{ $item->status ? 'Да' : 'Нет' }}</td>
                                                <td>{{ $pd->name ?? '—' }}</td>
                                                <td>
                                                    <a href="{{ route('admin.categories.edit', $item->id) }}" class="btn btn-info btn-sm">
                                                        <i class="fas fa-pencil-alt"></i>
                                                    </a>
                                                    <form action="{{ route('admin.categories.destroy', $item->id) }}" method="post" class="d-inline">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger btn-sm"
                                                                onclick="return confirm('Подтвердите удаление')">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7" class="text-center">Нет данных</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-center mt-3">
                                {{ $categories->links() }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
