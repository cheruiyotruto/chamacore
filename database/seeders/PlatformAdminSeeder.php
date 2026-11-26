<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class PlatformAdminSeeder extends Seeder
{
    /**
     * Known demo values must never become a production super-admin password.
     */
    private const DENIED_PASSWORDS = [
        'ChangeMe!2026', 'Password!2026', 'password', 'password123',
        'admin', 'admin123', 'changeme', 'changeme123',
    ];

    public function run(): void
    {
        $email = (string) env('PLATFORM_ADMIN_EMAIL', 'admin@chamacore.test');
        $password = (string) env('PLATFORM_ADMIN_PASSWORD', '');
        $production = app()->environment('production');

        if ($password === '') {
            if ($production) {
                throw new RuntimeException('PLATFORM_ADMIN_PASSWORD must be set before seeding in production.');
            }

            $password = Str::password(24);
            $this->command?->warn("No PLATFORM_ADMIN_PASSWORD set; generated a random one: {$password}");
        }

        if ($production) {
            if (strlen($password) < 12 || ! preg_match('/[A-Z]/', $password)
                || ! preg_match('/[a-z]/', $password) || ! preg_match('/\d/', $password)) {
                throw new RuntimeException(
                    'PLATFORM_ADMIN_PASSWORD must be at least 12 characters with upper, lower and numeric characters.'
                );
            }

            if (in_array($password, self::DENIED_PASSWORDS, true)) {
                throw new RuntimeException('PLATFORM_ADMIN_PASSWORD is a known default value. Choose a unique password.');
            }
        }

        $admin = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('PLATFORM_ADMIN_NAME', 'Platform Administrator'),
                'password' => Hash::make($password),
                'is_super_admin' => true,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->is_super_admin) {
            $admin->update(['is_super_admin' => true]);
        }

        $this->command?->info("Platform administrator ready: {$admin->email}");
    }
}
