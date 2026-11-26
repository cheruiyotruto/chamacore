<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('chama.subscription_plans', []) as $definition) {
            Plan::updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'price' => $definition['price'],
                    'interval' => $definition['interval'],
                    'trial_days' => array_key_exists('trial_days', $definition)
                        ? (int) $definition['trial_days']
                        : (int) config('chama.trial_days', 30),
                    'max_members' => $definition['max_members'],
                    'max_groups' => $definition['max_groups'],
                    'modules' => $definition['modules'],
                    'is_active' => true,
                ]
            );
        }
    }
}
