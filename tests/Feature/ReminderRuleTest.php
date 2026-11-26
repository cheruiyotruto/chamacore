<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_group_rule_overrides_the_platform_default_including_when_disabled(): void
    {
        $group = Group::factory()->create();

        ReminderRule::query()->delete();

        ReminderRule::create([
            'group_id' => null,
            'name' => 'Platform bill reminder',
            'type' => ReminderRule::TYPE_BILL_DUE,
            'offset_days' => -3,
            'audience' => ReminderRule::AUDIENCE_ALL,
            'is_active' => true,
        ]);

        $rules = app(ReminderService::class)->rulesFor($group);
        $this->assertCount(1, $rules);
        $this->assertSame('Platform bill reminder', $rules->first()->name);

        ReminderRule::create([
            'group_id' => $group->id,
            'name' => 'Group bill reminder',
            'type' => ReminderRule::TYPE_BILL_DUE,
            'offset_days' => -1,
            'audience' => ReminderRule::AUDIENCE_ALL,
            'is_active' => true,
        ]);

        $rules = app(ReminderService::class)->rulesFor($group);
        $this->assertCount(1, $rules);
        $this->assertSame('Group bill reminder', $rules->first()->name);

        $rules->first()->update(['is_active' => false]);

        $this->assertTrue(app(ReminderService::class)->rulesFor($group)->isEmpty());
    }

    public function test_sending_a_custom_date_reminder_is_idempotent(): void
    {
        [$group, $member] = $this->groupWithMember();

        $rule = ReminderRule::create([
            'group_id' => $group->id,
            'name' => 'Annual meeting',
            'type' => ReminderRule::TYPE_CUSTOM_DATE,
            'specific_date' => CarbonImmutable::today()->toDateString(),
            'audience' => ReminderRule::AUDIENCE_ALL,
            'subject' => 'Annual meeting tomorrow',
            'body' => 'Please attend the annual meeting.',
            'channels' => ['database'],
            'is_active' => true,
        ]);

        $service = app(ReminderService::class);
        $date = CarbonImmutable::today();

        $this->assertSame(['sent' => 1, 'skipped' => 0], $service->sendDue($group, $date));
        $this->assertSame(['sent' => 0, 'skipped' => 1], $service->sendDue($group, $date));

        $this->assertSame(1, ReminderDelivery::withoutGlobalScopes()
            ->where('reminder_rule_id', $rule->id)
            ->where('member_id', $member->id)
            ->count());
    }

    public function test_send_reminders_command_honours_the_date_option(): void
    {
        [$group] = $this->groupWithMember();
        $date = CarbonImmutable::today()->addDays(10);

        ReminderRule::create([
            'group_id' => $group->id,
            'name' => 'Future notice',
            'type' => ReminderRule::TYPE_CUSTOM_DATE,
            'specific_date' => $date->toDateString(),
            'audience' => ReminderRule::AUDIENCE_ALL,
            'is_active' => true,
        ]);

        $this->artisan('chama:send-reminders', [
            '--group' => $group->id,
            '--date' => $date->toDateString(),
        ])
            ->expectsOutputToContain('Sent 1 reminder(s)')
            ->assertExitCode(0);

        $this->artisan('chama:send-reminders', [
            '--group' => $group->id,
            '--date' => $date->toDateString(),
        ])
            ->expectsOutputToContain('Sent 0 reminder(s); 1 already sent')
            ->assertExitCode(0);

        $this->assertSame(1, ReminderDelivery::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->count());
    }

    /**
     * @return array{0:Group,1:Member}
     */
    private function groupWithMember(): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);

        $role = \App\Models\Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'member')
            ->firstOrFail();

        $member = Member::factory()
            ->forGroup($group)
            ->forUser(User::factory()->create())
            ->create([
                'role_id' => $role->id,
                'status' => 'active',
            ]);

        return [$group, $member];
    }
}
