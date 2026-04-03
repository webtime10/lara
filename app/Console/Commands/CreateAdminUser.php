<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {--name=akvamarin01 : Имя (логин, если не email)}
                            {--email=akvamarin01@admin.local : Email}
                            {--password=sandra2201 : Пароль}';

    protected $description = 'Создать или обновить администратора (role_id + роль admin)';

    public function handle(): int
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        $adminRole = Role::query()->where('slug', 'admin')->first();
        if (! $adminRole) {
            $this->error('В таблице roles нет записи со slug=admin. Сначала выполните: php artisan db:seed --class=RoleSeeder');

            return 1;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role_id' => $adminRole->id,
                'role' => 'admin',
            ]
        );

        $this->info('Администратор сохранён.');
        $this->line('Имя (логин): '.$name);
        $this->line('Email: '.$email);
        $this->line('Пароль: '.$password);
        $this->newLine();
        $this->comment('Вход: /login — в поле «Email или имя» укажите '.$name.' или '.$email);

        return 0;
    }
}
