<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'group_id' => Group::factory(),
            'user_id' => User::factory(),
            'role_id' => null,
            'member_no' => 'M-'.Str::upper(fake()->unique()->bothify('####??')),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'national_id' => fake()->unique()->numerify('########'),
            'phone' => '+2547'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'gender' => fake()->randomElement(['female', 'male']),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-21 years'),
            'address' => fake()->address(),
            'occupation' => fake()->jobTitle(),
            'membership_category' => 'ordinary',
            'status' => 'active',
            'kyc_status' => 'verified',
            'joined_at' => now()->subMonths(fake()->numberBetween(1, 24)),
            'membership_fee_paid' => 100000,
            'metadata' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'applied',
            'kyc_status' => 'pending',
            'joined_at' => null,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'first_name' => Str::before($user->name, ' ') ?: $user->name,
            'last_name' => Str::after($user->name, ' ') ?: 'Member',
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
    }

    public function forGroup(Group $group): static
    {
        return $this->state(fn () => ['group_id' => $group->id]);
    }
}
