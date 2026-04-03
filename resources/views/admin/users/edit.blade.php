@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Users - Редактировать</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Users</a></li>
                        <li class="breadcrumb-item active">Редактировать</li>
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

                            <form action="{{ route('admin.users.update', $user) }}" method="POST">
                                @csrf
                                @method('PUT')
                                
                                <div class="form-group">
                                    <label for="name">Имя *</label>
                                    <input type="text" 
                                           class="form-control @error('name') is-invalid @enderror" 
                                           id="name" 
                                           name="name" 
                                           value="{{ old('name', $user->name) }}" 
                                           required>
                                    @error('name')
                                        <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="form-group">
                                    <label for="email">Email (логин) *</label>
                                    <input type="email" 
                                           class="form-control @error('email') is-invalid @enderror" 
                                           id="email" 
                                           name="email" 
                                           value="{{ old('email', $user->email) }}" 
                                           required>
                                    @error('email')
                                        <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Email используется для входа в админку</small>
                                </div>

                                <div class="form-group">
                                    <label>Текущий пароль</label>
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle"></i> Пароль установлен. Используйте поле ниже для изменения пароля.
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="password">Новый пароль</label>
                                    <div class="input-group">
                                        <input type="password" 
                                               class="form-control @error('password') is-invalid @enderror" 
                                               id="password" 
                                               name="password" 
                                               minlength="6"
                                               placeholder="Введите новый пароль">
                                        <div class="input-group-append">
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    onclick="togglePasswordVisibility('password', 'passwordToggleIcon')">
                                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                    @error('password')
                                        <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Оставьте пустым, если не хотите менять пароль. Минимум 6 символов</small>
                                </div>

                                <div class="form-group">
                                    <label for="password_confirmation">Подтверждение пароля</label>
                                    <div class="input-group">
                                        <input type="password" 
                                               class="form-control" 
                                               id="password_confirmation" 
                                               name="password_confirmation" 
                                               minlength="6"
                                               placeholder="Повторите новый пароль">
                                        <div class="input-group-append">
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    onclick="togglePasswordVisibility('password_confirmation', 'confirmPasswordToggleIcon')">
                                                <i class="fas fa-eye" id="confirmPasswordToggleIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                function togglePasswordVisibility(inputId, iconId) {
                                    const input = document.getElementById(inputId);
                                    const icon = document.getElementById(iconId);
                                    
                                    if (input.type === 'password') {
                                        input.type = 'text';
                                        icon.classList.remove('fa-eye');
                                        icon.classList.add('fa-eye-slash');
                                    } else {
                                        input.type = 'password';
                                        icon.classList.remove('fa-eye-slash');
                                        icon.classList.add('fa-eye');
                                    }
                                }
                                </script>

                                <div class="form-group">
                                    <label for="role_id">Роль</label>
                                    <select class="form-control @error('role_id') is-invalid @enderror" 
                                            id="role_id" 
                                            name="role_id">
                                        <option value="">-- Выберите роль --</option>
                                        @foreach($roles as $role)
                                            <option value="{{ $role->id }}" {{ old('role_id', $user->role_id) == $role->id ? 'selected' : '' }}>
                                                {{ $role->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('role_id')
                                        <span class="invalid-feedback">{{ $message }}</span>
                                    @enderror
                                    <small class="form-text text-muted">Роль определяет права доступа пользователя</small>
                                </div>

                                <div class="form-group">
                                    <button type="submit" class="btn btn-primary">Сохранить</button>
                                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Отмена</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

