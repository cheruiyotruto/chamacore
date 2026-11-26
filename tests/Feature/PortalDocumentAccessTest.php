<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_documents_are_hidden_until_admin_enables_group_access(): void
    {
        [$group, , $memberUser, $adminUser] = $this->fixture();

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee(route('portal.documents.index'))
            ->assertDontSee('My documents');

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.documents.index'))
            ->assertForbidden();

        $this->actingAs($adminUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Allow members to view documents in the portal');

        $this->actingAs($adminUser)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.portal-access.update'), [
                'member_documents_enabled' => 1,
                'access' => [
                    Member::withoutGlobalScopes()
                        ->where('group_id', $group->id)
                        ->where('user_id', $memberUser->id)
                        ->firstOrFail()->id => ['enabled' => PortalAccess::sectionKeys()],
                ],
            ])
            ->assertRedirect();

        $this->assertTrue(PortalAccess::memberDocumentsEnabled($group));

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.documents.index'))
            ->assertOk();

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee(route('portal.documents.index'))
            ->assertSee('My documents');
    }

    public function test_member_documents_still_require_the_individual_member_permission(): void
    {
        [$group, $member, $memberUser, $adminUser] = $this->fixture();

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => PortalAccess::MEMBER_DOCUMENTS_SETTING],
            ['value' => true, 'updated_by' => $adminUser->id]
        );

        $member->portal_sections = ['disabled' => ['documents']];
        $member->save();

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.documents.index'))
            ->assertForbidden();

        $this->actingAs($adminUser)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.portal-access.update'), [
                'member_documents_enabled' => 1,
                'access' => [
                    $member->id => ['enabled' => PortalAccess::sectionKeys()],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('portal.documents.index'))
            ->assertOk();
    }

    /**
     * @return array{0: Group, 1: Member, 2: User, 3: User}
     */
    private function fixture(): array
    {
        $group = Group::factory()->create();

        $memberRole = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'member'],
            [
                'name' => config('chama.roles.member', 'Member'),
                'permissions' => config('chama.role_permissions.member', []),
                'is_system' => true,
            ]
        );

        $memberUser = User::factory()->create();
        $member = Member::factory()
            ->forGroup($group)
            ->forUser($memberUser)
            ->create([
                'role_id' => $memberRole->id,
                'status' => 'active',
            ]);

        $adminRole = Role::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'key' => 'group_admin'],
            [
                'name' => config('chama.roles.group_admin', 'Group admin'),
                'permissions' => config('chama.role_permissions.group_admin', ['*']),
                'is_system' => true,
            ]
        );

        $adminUser = User::factory()->create();
        Member::factory()
            ->forGroup($group)
            ->forUser($adminUser)
            ->create([
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]);

        return [$group, $member, $memberUser, $adminUser];
    }
}
