<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchService;
use App\Support\GroupContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_branch_bootstraps_an_isolated_tenant(): void
    {
        [$root, $admin, $user] = $this->organisationWithAdmin();

        $branch = app(BranchService::class)->create($root, [
            'name' => 'Mombasa Branch',
            'branch_code' => 'MOM',
            'county' => 'Mombasa',
        ], $user);

        $this->assertTrue($branch->isBranch());
        $this->assertSame($root->id, $branch->parent_group_id);
        $this->assertSame($root->id, $branch->billingOwner()->id);
        $this->assertSame($root->id, $branch->rootId());
        $this->assertSame($root->name.' / Mombasa Branch', $branch->branchPath());

        $this->assertDatabaseHas('groups', [
            'id' => $branch->id,
            'parent_group_id' => $root->id,
            'is_branch' => 1,
            'branch_code' => 'MOM',
            'subscription_status' => $root->subscription_status,
        ]);

        $this->assertGreaterThan(0, Role::withoutGlobalScopes()->where('group_id', $branch->id)->count());
        $this->assertGreaterThan(0, LedgerAccount::withoutGlobalScopes()->where('group_id', $branch->id)->count());

        $this->assertDatabaseHas('members', [
            'group_id' => $branch->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertTrue(in_array($branch->id, $root->descendantIds(), true));
        $this->assertSame([$root->id], $branch->ancestorIds());
    }

    public function test_a_group_admin_can_create_and_list_branches(): void
    {
        [$root, $admin, $user] = $this->organisationWithAdmin();

        $this->actingAs($user)
            ->post(route('branches.store'), [
                'name' => 'Nairobi Branch',
                'branch_code' => 'NBO',
                'county' => 'Nairobi',
            ])
            ->assertRedirect(route('branches.index'));

        $this->assertDatabaseHas('groups', [
            'name' => 'Nairobi Branch',
            'branch_code' => 'NBO',
            'parent_group_id' => $root->id,
            'is_branch' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertSee('Nairobi Branch');
    }

    public function test_an_unrelated_group_cannot_manage_another_organisations_branch(): void
    {
        [$root, $admin, $user] = $this->organisationWithAdmin();

        $branch = app(BranchService::class)->create($root, [
            'name' => 'Kisumu Branch',
        ], $user);

        [$otherRoot, $otherAdmin, $otherUser] = $this->organisationWithAdmin();

        $this->actingAs($otherUser)
            ->put(route('branches.update', $branch), [
                'name' => 'Taken Branch',
                'branch_code' => 'TAKEN',
            ])
            ->assertNotFound();

        $this->assertSame('Kisumu Branch', $branch->fresh()->name);
    }

    public function test_a_branch_uses_the_head_office_subscription_and_can_be_opened(): void
    {
        [$root, $admin, $user] = $this->organisationWithAdmin();

        $branch = app(BranchService::class)->create($root, [
            'name' => 'Nakuru Branch',
        ], $user);

        $this->assertSame($root->id, $branch->billingOwner()->id);

        $this->actingAs($user)
            ->withSession(['active_group_id' => $branch->id])
            ->get(route('dashboard'))
            ->assertOk();
    }

    /**
     * @return array{0:Group,1:Member,2:User}
     */
    private function organisationWithAdmin(): array
    {
        $root = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($root);

        $user = User::factory()->create();
        $role = Role::withoutGlobalScopes()
            ->where('group_id', $root->id)
            ->where('key', 'group_admin')
            ->firstOrFail();

        $admin = Member::factory()
            ->forGroup($root)
            ->forUser($user)
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
                'membership_category' => 'staff',
            ]);

        app(GroupContext::class)->setMembership($admin->load('role', 'group'));

        return [$root, $admin, $user];
    }
}
