<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\JoinRequirements;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalInvitationPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_sees_all_invitee_points_by_default(): void
    {
        [$group, $user] = $this->memberFor();

        $this->actingAs($user)
            ->get(route('portal.invitations.index'))
            ->assertOk()
            ->assertSee('What the invitee provides')
            ->assertSee('Personal, contact and next-of-kin details.')
            ->assertSee('A passport photo and/or face video, stored in the EDMS under their member number.')
            ->assertSee('An optional biometric capture, which administrators can require for a specific invitation.')
            ->assertSee('Their application then waits for a group official to approve it.');
    }

    public function test_admin_can_hide_the_card_and_choose_which_points_members_see(): void
    {
        [$group, $memberUser] = $this->memberFor();
        $admin = $this->adminFor($group);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.join-requirements.update'), [
                'requirements' => [
                    'portal_points_enabled' => 1,
                    'portal_points' => [
                        'details' => 1,
                        'identity' => 0,
                        'biometric' => 0,
                        'approval' => 1,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Joining form requirements saved.');

        $this->actingAs($memberUser)
            ->get(route('portal.invitations.index'))
            ->assertOk()
            ->assertSee('What the invitee provides')
            ->assertSee('Personal, contact and next-of-kin details.')
            ->assertSee('Their application then waits for a group official to approve it.')
            ->assertDontSee('A passport photo and/or face video, stored in the EDMS under their member number.')
            ->assertDontSee('An optional biometric capture, which administrators can require for a specific invitation.');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.join-requirements.update'), [
                'requirements' => ['portal_points_enabled' => 0],
            ])
            ->assertRedirect();

        $this->actingAs($memberUser)
            ->get(route('portal.invitations.index'))
            ->assertOk()
            ->assertDontSee('What the invitee provides');
    }

    public function test_settings_page_exposes_the_point_toggles_and_saves_unchecked_boxes_as_hidden(): void
    {
        [$group] = $this->memberFor();
        $admin = $this->adminFor($group);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('What members see when inviting')
            ->assertSee('name="requirements[portal_points_enabled]"', false)
            ->assertSee('name="requirements[portal_points][identity]"', false);

        $config = JoinRequirements::normalize([
            'portal_points_enabled' => 0,
            'portal_points' => ['details' => 0, 'identity' => 1],
        ]);

        $this->assertFalse($config['portal_points_enabled']);
        $this->assertFalse($config['portal_points']['details']);
        $this->assertTrue($config['portal_points']['identity']);
        $this->assertSame([], (new JoinRequirements($config))->visiblePortalPoints());
    }

    /**
     * @return array{Group, User}
     */
    private function memberFor(): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);

        $user = User::factory()->create();
        $role = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'member')
            ->firstOrFail();

        Member::factory()->forGroup($group)->forUser($user)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return [$group, $user];
    }

    private function adminFor(Group $group): User
    {
        $role = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'group_admin')
            ->firstOrFail();

        $user = User::factory()->create();

        Member::factory()->forGroup($group)->forUser($user)->create([
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
