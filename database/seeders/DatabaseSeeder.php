<?php

namespace Database\Seeders;

use App\Support\GroupContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(GroupContext::class)->withoutTenantScope(function () {
            $this->call([
                PlanSeeder::class,
                PlatformAdminSeeder::class,
            ]);

            if (filter_var(env('CHAMA_SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
                $this->call(DemoGroupSeeder::class);
            }

            $this->call(RoleSeeder::class);
        });
    }
}
