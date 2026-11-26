<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberEdmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberBiometricRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_require_biometric_for_one_member_and_approval_checks_it(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0300');

        $member->update([
            'status' => 'applied',
            'metadata' => [],
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->patch(route('members.update', $member), [
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'role_id' => $member->role_id,
                'status' => 'applied',
                'workstation' => 'Nakuru Town',
                'biometric_required' => '1',
            ])
            ->assertRedirect(route('members.show', $member));

        $member->refresh();

        $this->assertTrue($member->biometricRequired());
        $this->assertSame('Nakuru Town', $member->workstation);
        $this->assertContains(
            'Biometric capture',
            app(MemberEdmsService::class)->missingRequiredDocuments($member)
        );

        $this->post(route('members.approve', $member))
            ->assertSessionHas('error');

        $this->assertSame('applied', $member->fresh()->status);

        Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'biometric',
            'title' => 'Fingerprint template',
            'path' => 'edms/groups/'.$group->id.'/members/m0300/fingerprint.png',
            'disk' => 'documents',
            'mime_type' => 'image/png',
            'status' => 'verified',
        ]);

        $this->assertSame([], app(MemberEdmsService::class)->missingRequiredDocuments($member->fresh()));
    }

    /**
     * @return array{0: Member, 1: User}
     */
    private function memberWithRole(Group $group, string $roleKey, string $memberNo): array
    {
        $role = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => $roleKey],
            [
                'name' => config('chama.roles.'.$roleKey, ucfirst($roleKey)),
                'permissions' => config('chama.role_permissions.'.$roleKey, []),
                'is_system' => true,
            ]
        );

        $user = User::factory()->create();

        $member = Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'member_no' => $memberNo,
                'status' => 'active',
            ]);

        return [$member, $user];
    }

    private function userWithRole(Group $group, string $roleKey): User
    {
        return $this->memberWithRole($group, $roleKey, 'M-STAFF-'.strtoupper($roleKey))[1];
    }
}
