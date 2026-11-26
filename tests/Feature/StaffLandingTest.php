<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_login_lands_in_the_organisation_portal(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($group->name)
            ->assertSee('Organisation portal')
            ->assertSee('Organisation overview')
            ->assertSee(route('portal.dashboard'), false)
            ->assertSee(route('savings.index'), false)
            ->assertSee(route('loans.index'), false)
            ->assertDontSee('My savings')
            ->assertDontSee('Loan balance')
            ->assertDontSee('Member #')
            ->assertDontSee('Member savings')
            ->assertDontSee('Loan book')
            ->assertDontSee('Portfolio at risk')
            ->assertDontSee('Pending guarantees')
            ->assertDontSee('Collections')
            ->assertDontSee('Recent savings activity')
            ->assertDontSee('Recent loan repayments')
            ->assertDontSee('New loan');
    }

    public function test_old_staff_launcher_redirects_to_the_organisation_portal(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('staff.home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_organisation_portal_only_shows_sections_the_officer_can_access(): void
    {
        [$group, $teller] = $this->userWithRole('teller');

        $this->actingAs($teller)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('teller.index'), false)
            ->assertDontSee(route('settings.index'), false);
    }

    public function test_staff_can_still_open_the_personal_member_portal_explicitly(): void
    {
        [$group, $admin] = $this->userWithRole('group_admin');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('My savings')
            ->assertSee('Organisation portal');
    }

    public function test_member_login_still_lands_directly_in_the_member_portal(): void
    {
        [$group, $memberUser] = $this->userWithRole('member');

        $this->post(route('login.store'), [
            'email' => $memberUser->email,
            'password' => 'password',
        ])->assertRedirect(route('portal.dashboard'));

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('staff.home'))
            ->assertForbidden();
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function userWithRole(string $roleKey): array
    {
        $group = Group::factory()->create();

        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => $roleKey],
            [
                'name' => config('chama.roles.'.$roleKey, ucfirst($roleKey)),
                'permissions' => config('chama.role_permissions.'.$roleKey, []),
                'is_system' => true,
            ]
        );

        $user = User::factory()->create();

        Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
                'membership_category' => 'ordinary',
            ]);

        return [$group, $user];
    }
}
