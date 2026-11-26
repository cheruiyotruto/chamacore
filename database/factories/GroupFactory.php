<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Chama';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'registration_no' => 'REG-'.fake()->unique()->numerify('######'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2547'.fake()->unique()->numerify('########'),
            'county' => 'Nairobi',
            'sub_county' => 'Westlands',
            'address' => fake()->address(),
            'currency' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'status' => 'active',
            'subscription_status' => 'active',
            'fiscal_year_start_month' => 1,
            'settings' => [],
            'description' => fake()->sentence(),
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays(30),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays(30),
        ]);
    }
}
