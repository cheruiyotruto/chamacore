<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrencySettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_enable_and_disable_currencies(): void
    {
        [$group, $admin] = $this->fixture();

        $this->actingAs($admin)
            ->get(route('platform.currencies.index'))
            ->assertOk()
            ->assertSee('Available currencies')
            ->assertSee('KES')
            ->assertSee('USD');

        $this->actingAs($admin)
            ->put(route('platform.currencies.update'), [
                'currencies' => ['KES', 'USD', 'EUR'],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $setting = PlatformSetting::query()->where('key', CurrencySettings::KEY)->firstOrFail();

        $this->assertSame(['KES', 'USD', 'EUR'], $setting->value);
        $this->assertSame($admin->id, $setting->updated_by);
        $this->assertSame(['KES', 'USD', 'EUR'], CurrencySettings::enabledCodes());
    }

    public function test_non_super_admin_cannot_manage_currencies(): void
    {
        [$group] = $this->fixture();
        $groupAdmin = $this->groupAdmin($group);

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('platform.currencies.index'))
            ->assertForbidden();

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('platform.currencies.update'), ['currencies' => ['USD']])
            ->assertForbidden();
    }

    public function test_currency_update_requires_a_known_currency(): void
    {
        [, $admin] = $this->fixture();

        $this->actingAs($admin)
            ->put(route('platform.currencies.update'), ['currencies' => []])
            ->assertSessionHasErrors('currencies');

        $this->actingAs($admin)
            ->put(route('platform.currencies.update'), ['currencies' => ['XYZ']])
            ->assertSessionHasErrors('currencies.*');

        $this->assertDatabaseMissing('platform_settings', ['key' => CurrencySettings::KEY]);
    }

    public function test_group_admin_can_only_select_enabled_currencies(): void
    {
        [$group, $admin] = $this->fixture();

        CurrencySettings::save(['KES', 'USD'], $admin->id);

        $groupAdmin = $this->groupAdmin($group);

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('USD — US Dollar ($)', false)
            ->assertDontSee('TZS — Tanzanian Shilling (TSh)', false);

        $payload = $this->groupPayload($group, 'USD');

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.group.update'), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('USD', $group->fresh()->currency);

        $payload['currency'] = 'TZS';

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.group.update'), $payload)
            ->assertSessionHasErrors('currency');

        $this->assertSame('USD', $group->fresh()->currency);
    }

    public function test_disabled_current_currency_remains_editable_until_replaced(): void
    {
        [$group, $admin] = $this->fixture();

        $group->update(['currency' => 'TZS']);
        CurrencySettings::save(['KES', 'USD'], $admin->id);

        $groupAdmin = $this->groupAdmin($group);

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('TZS — Tanzanian Shilling (TSh) (current)', false);

        $this->actingAs($groupAdmin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.group.update'), $this->groupPayload($group, 'TZS'))
            ->assertRedirect();

        $this->assertSame('TZS', $group->fresh()->currency);
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function fixture(): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);

        return [$group, User::factory()->superAdmin()->create()];
    }

    private function groupAdmin(Group $group): User
    {
        $role = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'group_admin')
            ->firstOrFail();

        $user = User::factory()->create();

        Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
            ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupPayload(Group $group, string $currency): array
    {
        return [
            'name' => $group->name,
            'registration_no' => $group->registration_no,
            'email' => $group->email,
            'phone' => $group->phone,
            'county' => $group->county,
            'sub_county' => $group->sub_county,
            'address' => $group->address,
            'currency' => $currency,
            'timezone' => $group->timezone ?: 'Africa/Nairobi',
            'fiscal_year_start_month' => $group->fiscal_year_start_month ?: 1,
            'description' => $group->description,
        ];
    }
}
