
@extends('admin.layouts.layout')

@section('content')
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Админ-панель</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Home</a></li>
                        <li class="breadcrumb-item active">Dashboard</li>
                    </ol>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <!-- Default box -->
            <div class="card">
                <div class="card-body">
                    <p>Добро пожаловать в админ-панель!</p>
                    <div class="mt-3">
                        <button type="button" class="btn btn-primary btn-sm mr-2 js-api-check" data-url="{{ route('admin.api-check.openai') }}">
                            ОПН
                        </button>
                        <button type="button" class="btn btn-info btn-sm js-api-check" data-url="{{ route('admin.api-check.gemini') }}">
                            Джемини
                        </button>
                    </div>
                </div>
                <!-- /.card-body -->
            </div>
            <!-- /.card -->
        </div>
    </section>
    <!-- /.content -->

    <script>
        $(function () {
            $('.js-api-check').on('click', function () {
                var $btn = $(this);
                var oldText = $btn.text();

                $btn.prop('disabled', true).text('Проверка...');

                $.ajax({
                    url: $btn.data('url'),
                    method: 'GET',
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                    .done(function (res) {
                        alert((res && res.message) ? res.message : 'OK, есть подключение.');
                    })
                    .fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : 'Нет подключения.';
                        alert(msg);
                    })
                    .always(function () {
                        $btn.prop('disabled', false).text(oldText);
                    });
            });
        });
    </script>
@endsection
