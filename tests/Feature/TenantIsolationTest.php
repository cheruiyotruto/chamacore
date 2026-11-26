<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Support\GroupContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_only_sees_their_own_group_and_membership(): void
    {
        $groupA = Group::factory()->create();
        $groupB = Group::factory()->create();

        $memberA = $this->membership($groupA, 'member');
        $memberB = $this->membership($groupB, 'member');

        Wallet::withoutGlobalScopes()->create([
            'group_id' => $groupA->id,
            'member_id' => $memberA->id,
            'balance' => 1000,
            'available_balance' => 1000,
        ]);

        Wallet::withoutGlobalScopes()->create([
            'group_id' => $groupB->id,
            'member_id' => $memberB->id,
            'balance' => 5000,
            'available_balance' => 5000,
        ]);

        $context = app(GroupContext::class);
        $context->setMembership($memberA->load('role', 'group'));

        $this->assertSame($groupA->id, $context->groupId());
        $this->assertSame($memberA->id, $context->memberId());
        $this->assertSame(1, Member::count());
        $this->assertNull(Member::whereKey($memberB->id)->first());
        $this->assertSame(1, Wallet::count());
        $this->assertNull(Wallet::where('member_id', $memberB->id)->first());
        $this->assertTrue($context->canAccessMember($memberA));
        $this->assertFalse($context->canAccessMember($memberB));
    }

    public function test_group_staff_see_their_group_only(): void
    {
        $groupA = Group::factory()->create();
        $groupB = Group::factory()->create();

        $this->membership($groupA, 'member');
        $staff = $this->membership($groupA, 'treasurer');
        $other = $this->membership($groupB, 'member');

        $context = app(GroupContext::class);
        $context->setMembership($staff->load('role', 'group'));

        $this->assertSame(2, Member::count());
        $this->assertNull(Member::whereKey($other->id)->first());
        $this->assertTrue($context->isStaff());
        $this->assertFalse($context->isMember());
    }

    private function membership(Group $group, string $roleKey): Member
    {
        $role = Role::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'key' => $roleKey,
            'name' => ucfirst($roleKey),
            'permissions' => config("chama.role_permissions.{$roleKey}", []),
            'is_system' => true,
        ]);

        $user = User::factory()->create();

        return Member::factory()
            ->forGroup($group)
            ->forUser($user)
            ->create(['role_id' => $role->id]);
    }
}
