<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Support\JoinRequirements;
use App\Support\GroupResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicMemberSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_directory_only_lists_groups_opted_in_by_a_super_admin(): void
    {
        $open = $this->group([
            'name' => 'Open Umoja Chama',
            'public_join_summary' => 'We welcome new members from all counties.',
        ]);

        $private = $this->group([
            'name' => 'Private Chama Twelve',
            'public_join_enabled' => false,
        ]);

        $selfManaged = $this->group([
            'name' => 'Independent Chama Nine',
            'platform_management_mode' => Group::PLATFORM_MODE_SELF,
        ]);

        $suspended = $this->group([
            'name' => 'Suspended Chama Four',
            'status' => 'suspended',
        ]);

        $branch = Group::factory()->create([
            'name' => 'Mombasa Branch Chama',
            'parent_group_id' => $open->id,
            'is_branch' => true,
            'branch_code' => 'MOM',
            'public_join_enabled' => true,
            'platform_management_mode' => Group::PLATFORM_MODE_OVERSIGHT,
        ]);

        $this->get(route('public.join.index'))
            ->assertOk()
            ->assertSee('Open Umoja Chama')
            ->assertSee('We welcome new members from all counties.')
            ->assertDontSee('Private Chama Twelve')
            ->assertDontSee('Independent Chama Nine')
            ->assertDontSee('Suspended Chama Four')
            ->assertDontSee('Mombasa Branch Chama');

        $this->get(route('public.join.apply', $open))
            ->assertOk()
            ->assertSee('Join Open Umoja Chama')
            ->assertSee('Submit membership application');

        foreach ([$private, $selfManaged, $suspended, $branch] as $group) {
            $this->get(route('public.join.apply', $group))->assertNotFound();
        }
    }

    public function test_public_application_stays_pending_stores_edms_files_and_blocks_dashboard_access(): void
    {
        Storage::fake('documents');
        Notification::fake();

        $group = $this->group(['name' => 'Public Umoja Chama']);

        $this->post(route('public.join.store', $group), $this->payload())
            ->assertRedirect(route('public.join.submitted', $group))
            ->assertSessionHas('status');

        $member = $this->applicant($group, 'grace@example.test');

        $this->assertSame('applied', $member->status);
        $this->assertSame('pending', $member->kyc_status);
        $this->assertSame('public website', data_get($member->metadata, 'application_source'));
        $this->assertNotNull(data_get($member->metadata, 'submitted_at'));
        $this->assertSame($group->roles()->where('key', 'member')->value('id'), $member->role_id);
        $this->assertSame(1, $member->nextOfKin()->count());

        $document = Document::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $member->id)
            ->where('type', 'passport_photo')
            ->firstOrFail();

        $this->assertSame('documents', $document->disk);
        $this->assertSame('pending', $document->status);
        $this->assertSame('staff', $document->visibility);
        $this->assertStringContainsString(
            'edms/groups/'.$group->id.'/members/'.strtolower($member->member_no).'/',
            $document->path
        );
        Storage::disk('documents')->assertExists($document->path);
        $this->assertSame($document->path, $member->photo_path);

        $this->get(route('public.join.submitted', $group))
            ->assertOk()
            ->assertSee('Application received')
            ->assertSee('Public Umoja Chama');

        $this->actingAs($member->user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_duplicate_email_and_national_id_applications_are_rejected(): void
    {
        Notification::fake();

        $group = $this->group();

        $this->post(route('public.join.store', $group), $this->payload())
            ->assertRedirect(route('public.join.submitted', $group));

        $this->post(route('public.join.store', $group), $this->payload([
            'national_id' => '87654321',
        ]))->assertSessionHasErrors(['email']);

        $this->post(route('public.join.store', $group), $this->payload([
            'email' => 'another.applicant@example.test',
        ]))->assertSessionHasErrors(['national_id']);

        $this->assertSame(1, Member::withoutGlobalScopes()->where('group_id', $group->id)->count());
        $this->assertSame(1, User::where('email', 'grace@example.test')->count());
        $this->assertSame(0, User::where('email', 'another.applicant@example.test')->count());
    }

    public function test_super_admin_can_opt_a_group_in_and_out_of_public_signup(): void
    {
        $group = $this->group([
            'platform_management_mode' => Group::PLATFORM_MODE_SELF,
            'public_join_enabled' => false,
        ]);
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->create();

        $this->actingAs($member)
            ->put(route('platform.groups.public-join.update', $group), [
                'public_join_enabled' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('platform.groups.public-join.update', $group), [
                'public_join_enabled' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($group->fresh()->public_join_enabled);

        $group->update(['platform_management_mode' => Group::PLATFORM_MODE_OVERSIGHT]);

        $this->actingAs($admin)
            ->put(route('platform.groups.public-join.update', $group), [
                'public_join_enabled' => 1,
                'public_join_summary' => 'Now accepting applications.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($group->fresh()->public_join_enabled);
        $this->assertSame('Now accepting applications.', $group->fresh()->public_join_summary);

        $this->get(route('public.join.index'))
            ->assertOk()
            ->assertSee($group->name)
            ->assertSee('Now accepting applications.');

        $this->actingAs($admin)
            ->put(route('platform.groups.public-join.update', $group), [
                'public_join_summary' => 'Closed for now.',
            ])
            ->assertRedirect();

        $this->assertFalse($group->fresh()->public_join_enabled);
        $this->assertSame('Closed for now.', $group->fresh()->public_join_summary);

        $this->flushSession();
        $this->get(route('public.join.index'))->assertDontSee($group->name);
    }

    public function test_super_admin_can_review_approve_and_reject_public_applications(): void
    {
        Notification::fake();

        $group = $this->group(['name' => 'Review Umoja Chama']);
        $admin = User::factory()->superAdmin()->create();
        $approved = $this->submitApplication($group, ['email' => 'approve.me@example.test']);
        $rejected = $this->submitApplication($group, [
            'email' => 'reject.me@example.test',
            'national_id' => '55443322',
            'phone' => '+254700000001',
            'first_name' => 'Brian',
        ]);

        $this->actingAs($admin)
            ->get(route('platform.applications.index'))
            ->assertOk()
            ->assertSee('approve.me@example.test')
            ->assertSee('reject.me@example.test')
            ->assertSee('Review Umoja Chama')
            ->assertSee('public website');

        $this->actingAs($admin)
            ->post(route('platform.applications.approve', $approved))
            ->assertRedirect();

        $approved->refresh();

        $this->assertSame('active', $approved->status);
        $this->assertSame('verified', $approved->kyc_status);
        $this->assertSame($admin->id, $approved->approved_by);
        $this->assertNotNull($approved->joined_at);
        $this->assertSame(1, Wallet::withoutGlobalScopes()->where('member_id', $approved->id)->count());

        AuditLog::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('action', 'member.approved')
            ->firstOrFail();

        $this->actingAs($approved->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('portal.dashboard'));

        $this->actingAs($approved->user)
            ->get(route('portal.dashboard'))
            ->assertOk();

        $this->assertNull(session(GroupResolver::FULL_CONTROL_KEY));

        $this->actingAs($admin)
            ->post(route('platform.applications.reject', $rejected), [
                'reason' => 'Duplicate identity records.',
            ])
            ->assertRedirect();

        $rejected->refresh();

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Duplicate identity records.', $rejected->rejection_reason);
        $this->assertSame($admin->id, $rejected->approved_by);

        $this->actingAs($rejected->user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_required_documents_block_approval_until_explicitly_overridden(): void
    {
        Storage::fake('documents');
        Notification::fake();

        $group = $this->group();

        GroupSetting::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'key' => JoinRequirements::SETTING_KEY,
            'value' => [
                'documents' => [
                    'signed_application' => ['enabled' => true, 'required' => true],
                ],
            ],
        ]);

        $payload = $this->payload([
            'documents' => [
                'signed_application' => UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'),
            ],
        ]);

        $this->post(route('public.join.store', $group), $payload)
            ->assertRedirect(route('public.join.submitted', $group));

        $member = $this->applicant($group, 'grace@example.test');

        $this->assertSame(['signed_application'], array_column(
            data_get($member->metadata, 'application_documents.required', []),
            'key'
        ));

        $document = Document::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('type', 'membership_form')
            ->firstOrFail();

        Document::withoutGlobalScopes()
            ->where('id', $document->id)
            ->update(['status' => 'rejected']);

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->from(route('platform.applications.index'))
            ->post(route('platform.applications.approve', $member))
            ->assertRedirect(route('platform.applications.index'))
            ->assertSessionHasErrors(['override_documents']);

        $this->assertSame('applied', $member->fresh()->status);

        $this->actingAs($admin)
            ->post(route('platform.applications.approve', $member), [
                'override_documents' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('active', $member->fresh()->status);

        AuditLog::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('action', 'member.approved.without_documents')
            ->firstOrFail();
    }

    public function test_application_form_guides_multi_angle_camera_recording(): void
    {
        $group = $this->group();

        $this->get(route('public.join.apply', $group))
            ->assertOk()
            ->assertSee('Record with your camera')
            ->assertSee('id="camera-preview"', false)
            ->assertSee('id="camera-prompt"', false)
            ->assertSee('id="camera-start"', false)
            ->assertSee('Slowly turn your head to your left', false)
            ->assertSee('turn your head to your right', false)
            ->assertSee('Tilt your head up slightly', false)
            ->assertSee('Tilt your head down slightly', false);
    }

    public function test_public_directory_also_opens_personal_invitations(): void
    {
        $group = $this->group(['name' => 'Invite Umoja Chama']);
        $inviter = Member::factory()->forGroup($group)->create();

        $invitation = MemberInvitation::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'inviter_member_id' => $inviter->id,
            'email' => 'invitee@example.test',
            'channels' => ['email'],
            'status' => MemberInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $this->get(route('public.join.index'))
            ->assertOk()
            ->assertSee('Have an invitation link?')
            ->assertSee('Use invitation');

        $this->get(route('public.join.index', ['invitation' => $invitation->token]))
            ->assertRedirect(route('join.show', $invitation->token));

        $this->get(route('public.join.index', [
            'invitation' => route('join.show', $invitation->token),
        ]))->assertRedirect(route('join.show', $invitation->token));

        $this->from(route('public.join.index'))
            ->get(route('public.join.index', ['invitation' => 'not-a-real-token']))
            ->assertRedirect(route('public.join.index'))
            ->assertSessionHas('error');

        $this->get(route('join.show', $invitation->token))
            ->assertOk()
            ->assertSee('Browse groups accepting applications');
    }

    public function test_unlisted_group_cannot_accept_public_applications(): void
    {
        $group = $this->group(['public_join_enabled' => false]);

        $this->get(route('public.join.apply', $group))->assertNotFound();
        $this->get(route('public.join.submitted', $group))->assertNotFound();

        $this->post(route('public.join.store', $group), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Member::withoutGlobalScopes()->where('group_id', $group->id)->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function group(array $attributes = []): Group
    {
        $group = Group::factory()->create(array_merge([
            'status' => 'active',
            'subscription_status' => 'active',
            'platform_management_mode' => Group::PLATFORM_MODE_OVERSIGHT,
            'public_join_enabled' => true,
        ], $attributes));

        app(RoleSeeder::class)->seedForGroup($group);

        return $group;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Grace',
            'last_name' => 'Mwangi',
            'email' => 'grace@example.test',
            'phone' => '+254712345678',
            'national_id' => '12345678',
            'gender' => 'female',
            'date_of_birth' => '1993-04-12',
            'address' => '12 Market Street, Nairobi',
            'occupation' => 'Accountant',
            'employer' => 'Umoja Enterprises',
            'employer_address' => 'Nairobi',
            'membership_category' => 'ordinary',
            'next_of_kin_name' => 'Peter Mwangi',
            'next_of_kin_relationship' => 'Brother',
            'next_of_kin_phone' => '+254798765432',
            'passport_photo' => $this->photo(),
            'consent' => '1',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submitApplication(Group $group, array $overrides = []): Member
    {
        $this->post(route('public.join.store', $group), $this->payload($overrides))
            ->assertRedirect(route('public.join.submitted', $group));

        return $this->applicant($group, $overrides['email'] ?? 'grace@example.test');
    }

    private function applicant(Group $group, string $email): Member
    {
        return Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('email', $email)
            ->firstOrFail();
    }

    private function photo(): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        return UploadedFile::fake()->createWithContent('passport.png', $png);
    }
}
