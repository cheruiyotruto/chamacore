<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Services\InvitationService;
use App\Support\JoinRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JoinFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_page_renders_for_a_pending_invitation(): void
    {
        [$group, $inviter, $invitation] = $this->invitation();

        $this->get(route('join.show', $invitation->token))
            ->assertOk()
            ->assertSee($group->name)
            ->assertSee($inviter->full_name)
            ->assertSee('Personal details');
    }

    public function test_invitee_submits_application_and_identity_media_enters_the_edms(): void
    {
        Storage::fake('documents');

        [$group, , $invitation] = $this->invitation();

        $response = $this->post(route('join.store', $invitation->token), [
            'first_name' => 'Amina',
            'middle_name' => 'Wanjiku',
            'last_name' => 'Otieno',
            'email' => 'amina@example.test',
            'phone' => '+254712345678',
            'national_id' => '12345678',
            'gender' => 'female',
            'date_of_birth' => '1992-05-10',
            'address' => '12 Ngong Road, Nairobi',
            'occupation' => 'Teacher',
            'employer' => 'Riverside Academy',
            'employer_address' => 'Nairobi',
            'workstation' => 'Nairobi CBD',
            'membership_category' => 'ordinary',
            'next_of_kin_name' => 'John Otieno',
            'next_of_kin_relationship' => 'Spouse',
            'next_of_kin_phone' => '+254798765432',
            'passport_photo' => $this->fakePhoto(),
            'consent' => '1',
        ]);

        $response->assertRedirect(route('join.thanks', $invitation->token));

        $member = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('email', 'amina@example.test')
            ->firstOrFail();

        $this->assertSame('applied', $member->status);
        $this->assertSame('Nairobi CBD', $member->workstation);
        $this->assertMatchesRegularExpression('/^M\d{4,}$/', $member->member_no);

        $invitation->refresh();
        $this->assertSame(MemberInvitation::STATUS_ACCEPTED, $invitation->status);
        $this->assertSame($member->id, $invitation->accepted_member_id);

        $photo = Document::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $member->id)
            ->where('type', 'passport_photo')
            ->firstOrFail();

        $this->assertSame('documents', $photo->disk);
        $this->assertStringContainsString(
            'edms/groups/'.$group->id.'/members/'.strtolower($member->member_no).'/',
            $photo->path
        );
        Storage::disk('documents')->assertExists($photo->path);
        $this->assertSame($photo->path, $member->photo_path);

        $this->get(route('join.thanks', $invitation->token))
            ->assertOk()
            ->assertSee($member->member_no)
            ->assertSee('Application received');
    }

    public function test_expired_invitations_cannot_be_used(): void
    {
        [$group, , $invitation] = $this->invitation(['expires_at' => now()->subDay()]);

        $this->get(route('join.show', $invitation->token))
            ->assertOk()
            ->assertSee('not active');

        $response = $this->post(route('join.store', $invitation->token), $this->validPayload());

        $response->assertRedirect(route('join.show', $invitation->token));
        $response->assertSessionHas('error');

        $this->assertSame(1, Member::withoutGlobalScopes()->where('group_id', $group->id)->count());
    }

    public function test_face_video_is_stored_in_the_edms_under_the_member_number(): void
    {
        Storage::fake('documents');

        $group = Group::factory()->create();
        $member = Member::factory()->forGroup($group)->create(['member_no' => 'M0042']);
        $uploader = $member->user;

        $media = app(\App\Services\MemberEdmsService::class)->storeIdentityMedia(
            $group,
            $member,
            $uploader,
            null,
            UploadedFile::fake()->create('face.mp4', 200, 'video/mp4'),
            'Invitation test'
        );

        $this->assertNull($media['photo']);
        $this->assertNotNull($media['video']);
        $this->assertSame('face_video', $media['video']->type);
        $this->assertSame($member->id, $media['video']->member_id);
        $this->assertStringContainsString('edms/groups/'.$group->id.'/members/m0042/', $media['video']->path);
        Storage::disk('documents')->assertExists($media['video']->path);
    }

    public function test_admin_can_hide_and_optionalise_join_form_fields(): void
    {
        $requirements = new JoinRequirements([
            'fields' => [
                'occupation' => ['enabled' => false],
                'address' => ['enabled' => true, 'required' => false],
                'national_id' => ['enabled' => true, 'required' => true],
            ],
            'media' => [
                'passport_photo' => ['enabled' => true],
                'face_video' => ['enabled' => false],
            ],
            'identity_mode' => 'photo',
        ]);

        $this->assertFalse($requirements->fieldEnabled('occupation'));
        $this->assertFalse($requirements->fieldRequired('address'));
        $this->assertTrue($requirements->fieldRequired('national_id'));
        $this->assertSame('photo', $requirements->identityMode());

        $rules = $requirements->validationRules();

        $this->assertArrayNotHasKey('occupation', $rules);
        $this->assertContains('nullable', $rules['address']);
        $this->assertContains('required', $rules['national_id']);
        $this->assertArrayNotHasKey('face_video', $rules);
    }

    public function test_identity_mode_falls_back_when_media_is_disabled(): void
    {
        $requirements = new JoinRequirements([
            'media' => [
                'passport_photo' => ['enabled' => false],
                'face_video' => ['enabled' => false],
            ],
            'identity_mode' => 'both',
        ]);

        $this->assertSame('none', $requirements->identityMode());
        $this->assertFalse($requirements->anyMediaEnabled());
    }

    public function test_invitation_service_delivers_over_every_selected_channel(): void
    {
        Notification::fake();

        [$group, $inviter] = $this->invitation();

        $invitation = app(InvitationService::class)->create($inviter, [
            'email' => 'new.person@example.test',
            'phone' => '+254700111222',
            'channels' => ['email', 'sms', 'whatsapp'],
            'expires_in' => 14,
        ]);

        $this->assertSame(MemberInvitation::STATUS_PENDING, $invitation->status);

        foreach (['email', 'sms', 'whatsapp'] as $channel) {
            $this->assertSame('sent', $invitation->delivery[$channel]['status'], "{$channel} delivery");
        }

        $this->assertStringContainsString(route('join.show', $invitation->token), app(InvitationService::class)->message($invitation));

        Notification::assertSentOnDemand(MemberInvitationNotification::class);
    }

    public function test_biometric_capture_is_optional_by_default(): void
    {
        Storage::fake('documents');

        [$group, , $invitation] = $this->invitation();

        $this->get(route('join.show', $invitation->token))
            ->assertOk()
            ->assertSee('Biometric capture')
            ->assertSee('Optional unless your group requires it for your application.');

        $this->post(route('join.store', $invitation->token), $this->validPayload())
            ->assertRedirect(route('join.thanks', $invitation->token));

        $member = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('email', 'amina@example.test')
            ->firstOrFail();

        $this->assertFalse($member->biometricRequired());
        $this->assertSame('Nairobi CBD', $member->workstation);
        $this->assertSame(0, Document::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $member->id)
            ->where('type', 'biometric')
            ->count());
    }

    public function test_invitation_can_make_biometric_capture_mandatory_for_one_applicant(): void
    {
        Storage::fake('documents');

        [$group, , $invitation] = $this->invitation(['require_biometric' => true]);

        $this->get(route('join.show', $invitation->token))
            ->assertOk()
            ->assertSee('Biometric capture')
            ->assertSee('1 required document(s).');

        $this->post(route('join.store', $invitation->token), $this->validPayload())
            ->assertSessionHasErrors(['documents.biometric_template']);

        $payload = $this->validPayload();
        $payload['documents']['biometric_template'] = $this->fakePhoto();

        $this->post(route('join.store', $invitation->token), $payload)
            ->assertRedirect(route('join.thanks', $invitation->token));

        $member = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('email', 'amina@example.test')
            ->firstOrFail();

        $this->assertTrue($member->biometricRequired());

        $biometric = Document::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('member_id', $member->id)
            ->where('type', 'biometric')
            ->firstOrFail();

        Storage::disk('documents')->assertExists($biometric->path);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Group, 1: Member, 2: MemberInvitation}
     */
    private function invitation(array $overrides = []): array
    {
        $group = Group::factory()->create();

        Role::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'key' => 'member',
            'name' => 'Member',
            'permissions' => config('chama.role_permissions.member', []),
            'is_system' => true,
        ]);

        $inviter = Member::factory()->forGroup($group)->create();

        $invitation = MemberInvitation::withoutGlobalScopes()->create(array_merge([
            'group_id' => $group->id,
            'inviter_member_id' => $inviter->id,
            'email' => 'invitee@example.test',
            'phone' => '+254733444555',
            'channels' => ['email'],
            'status' => MemberInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
        ], $overrides));

        return [$group, $inviter, $invitation];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'first_name' => 'Amina',
            'last_name' => 'Otieno',
            'email' => 'amina@example.test',
            'phone' => '+254712345678',
            'national_id' => '12345678',
            'gender' => 'female',
            'date_of_birth' => '1992-05-10',
            'address' => '12 Ngong Road, Nairobi',
            'workstation' => 'Nairobi CBD',
            'membership_category' => 'ordinary',
            'next_of_kin_name' => 'John Otieno',
            'next_of_kin_relationship' => 'Spouse',
            'next_of_kin_phone' => '+254798765432',
            'passport_photo' => $this->fakePhoto(),
            'consent' => '1',
        ];
    }

    private function fakePhoto(): UploadedFile
    {
        // A real 1x1 PNG so validation works without the GD extension.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        return UploadedFile::fake()->createWithContent('passport.png', $png);
    }
}
