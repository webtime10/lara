@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item active">Сайт</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <a href="{{ route('admin.manufacturers.create') }}" class="btn btn-primary float-right">Добавить</a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table">
                        <thead><tr><th>ID</th><th>Название</th><th></th></tr></thead>
                        <tbody>
                            @forelse($manufacturers as $m)
                                <tr>
                                    <td>{{ $m->id }}</td>
                                    <td>{{ $m->name }}</td>
                                    <td>
                                        <a href="{{ route('admin.manufacturers.edit', $m->id) }}" class="btn btn-sm btn-info">Изм.</a>
                                        <form action="{{ route('admin.manufacturers.destroy', $m->id) }}" method="post" class="d-inline" onsubmit="return confirm('Удалить?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Удал.</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center">Нет записей</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $manufacturers->links() }}</div>
            </div>
        </div>
    </section>
@endsection
