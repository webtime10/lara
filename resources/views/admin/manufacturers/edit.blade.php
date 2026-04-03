@extends('admin.layouts.layout')

@section('content')
    <section class="content">
        <div class="container-fluid">
            <div class="card card-primary">
                <form action="{{ route('admin.manufacturers.update', $manufacturer->id) }}" method="post">
                    @csrf @method('PUT')
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                        @endif
                        <div class="form-group">
                            <label>Название <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $manufacturer->name) }}" required maxlength="64">
                        </div>
                        <div class="form-group">
                            <label>Картинка</label>
                            <input type="text" name="image" class="form-control" value="{{ old('image', $manufacturer->image) }}">
                        </div>
                        <div class="form-group">
                            <label>Порядок</label>
                            <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $manufacturer->sort_order) }}" min="0">
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                        <a href="{{ route('admin.manufacturers.index') }}" class="btn btn-default">Назад</a>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
