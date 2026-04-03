<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetAdminPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:reset-password {email=admin@example.com} {--password=admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset password for admin user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->option('password');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email {$email} not found!");
            return 1;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->info("Password reset successfully!");
        $this->info("Email: {$email}");
        $this->info("New Password: {$password}");
        $this->info("You can now login at /login");

        return 0;
    }
}

