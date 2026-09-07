<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Create (or update the password of) the single admin user from .env,
     * so `php artisan db:seed` is safe to re-run on every deploy.
     */
    public function run(): void
    {
        $email = config('auth.admin.email');
        $password = config('auth.admin.password');

        if (! $email || ! $password) {
            $this->command->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set in .env — skipping admin user seed.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => $password],
        );
    }
}
