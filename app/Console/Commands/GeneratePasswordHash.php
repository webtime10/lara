<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class GeneratePasswordHash extends Command
{
    protected $signature = 'password:hash {password : Пароль для хеширования}';

    protected $description = 'Вывести bcrypt-хеш для ручного UPDATE в БД (phpMyAdmin)';

    public function handle(): int
    {
        $password = $this->argument('password');
        $hash = Hash::make($password);

        $this->info('Пароль: '.$password);
        $this->info('Хеш (вставьте в SQL):');
        $this->line($hash);
        $this->newLine();
        $this->comment('Пример для phpMyAdmin:');
        $this->line("UPDATE `users` SET `password` = '".$hash."' WHERE `email` = 'akvamarin01@admin.local';");

        return 0;
    }
}
