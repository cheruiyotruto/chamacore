<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\JoinRequirements;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JoinDetailsAndSignaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_join_shows_live_signature_and_both_id_copy_uploads_by_default(): void
    {
        $group = $this->group();

        $this->get(route('public.join.apply', $group))
            ->assertOk()
            ->assertSee('data-signature-canvas', false)
            ->assertSee('National ID copy (front)')
            ->assertSee('National ID copy (back)')
            ->assertSee('name="documents[national_id_copy]"', false)
            ->assertSee('name="documents[national_id_copy_back]"', false)
            ->assertSee('Workstation / work location')
            ->assertSee('name="workstation"', false);
    }

    public function test_admin_can_publish_joining_details_and_enable_both_signature_methods(): void
    {
        $group = $this->group(['name' => 'Details Umoja Chama']);
        $admin = $this->adminFor($group);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Joining details')
            ->assertSee('name="requirements[joining_details]"', false)
            ->assertSee('KRA PIN number')
            ->assertSee('name="requirements[fields][kra_pin_number][enabled]"', false)
            ->assertSee('Workstation / work location')
            ->assertSee('name="requirements[fields][workstation][enabled]"', false)
            ->assertSee('Typed signature')
            ->assertSee('name="requirements[documents][electronic_signature][enabled]"', false)
            ->assertSee('name="requirements[documents][typed_signature][enabled]"', false);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->put(route('settings.join-requirements.update'), [
                'requirements' => [
                    'joining_details' => "Bring your original ID.\nWe will call you within seven days.",
                    'fields' => [
                        'kra_pin_number' => ['enabled' => 1, 'required' => 0],
                    ],
                    'documents' => [
                        'electronic_signature' => ['enabled' => 1, 'required' => 0],
                        'typed_signature' => ['enabled' => 1, 'required' => 0],
                    ],
                    'custom_documents' => [
                        ['label' => 'Bank statement', 'enabled' => 1, 'required' => 1],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Joining form requirements saved.');

        $stored = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', JoinRequirements::SETTING_KEY)
            ->firstOrFail()
            ->value;

        $this->assertSame("Bring your original ID.\nWe will call you within seven days.", $stored['joining_details']);
        $this->assertTrue($stored['fields']['kra_pin_number']['enabled']);
        $this->assertFalse($stored['fields']['kra_pin_number']['required']);
        $this->assertTrue($stored['documents']['electronic_signature']['enabled']);
        $this->assertTrue($stored['documents']['typed_signature']['enabled']);
        $this->assertTrue($stored['custom_documents'][0]['required']);

        $this->get(route('public.join.apply', $group))
            ->assertOk()
            ->assertSee('Bring your original ID.')
            ->assertSee('We will call you within seven days.')
            ->assertDontSee('Before you apply')
            ->assertDontSee('What Details Umoja Chama asks applicants to prepare.')
            ->assertDontSee('Details to have ready')
            ->assertDontSee('Drawn electronic signature (optional)')
            ->assertDontSee('Typed electronic signature (optional)')
            ->assertSee('name="kra_pin_number"', false)
            ->assertSee('data-signature-canvas', false)
            ->assertSee('name="typed_signature"', false)
            ->assertSee('name="typed_signature_consent"', false);
    }

    public function test_admin_can_disable_the_optional_kra_pin_number(): void
    {
        $group = $this->group();

        $this->saveRequirements($group, [
            'fields' => [
                'kra_pin_number' => ['enabled' => 0, 'required' => 0],
            ],
        ]);

        $this->get(route('public.join.apply', $group))
            ->assertOk()
            ->assertDontSee('KRA PIN number')
            ->assertDontSee('name="kra_pin_number"', false);

        Notification::fake();

        $this->post(route('public.join.store', $group), $this->payload([
            'kra_pin_number' => 'A012345678Z',
        ]))->assertRedirect(route('public.join.submitted', $group));

        $member = $this->applicant($group, 'grace@example.test');

        $this->assertNull(data_get($member->metadata, 'kra_pin_number'));
    }

    public function test_kra_pin_number_is_optional_and_stored_when_provided(): void
    {
        Notification::fake();

        $group = $this->group();

        $this->post(route('public.join.store', $group), $this->payload([
            'kra_pin_number' => 'A012345678Z',
        ]))->assertRedirect(route('public.join.submitted', $group));

        $member = $this->applicant($group, 'grace@example.test');

        $this->assertSame('A012345678Z', data_get($member->metadata, 'kra_pin_number'));

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('platform.applications.index'))
            ->assertOk()
            ->assertSee('KRA PIN A012345678Z');

        $this->post(route('public.join.store', $group), $this->payload([
            'email' => 'no.pin@example.test',
            'national_id' => '88776655',
            'phone' => '+254700000009',
            'kra_pin_number' => '',
        ]))->assertRedirect(route('public.join.submitted', $group));

        $this->assertNull(data_get($this->applicant($group, 'no.pin@example.test')->metadata, 'kra_pin_number'));
    }

    public function test_drawn_signature_still_works_and_is_stored_in_the_edms(): void
    {
        Storage::fake('documents');
        Notification::fake();

        $group = $this->group();

        $this->saveRequirements($group, [
            'documents' => [
                'electronic_signature' => ['enabled' => 1, 'required' => 1],
            ],
        ]);

        $this->get(route('public.join.apply', $group))
            ->assertOk()
            ->assertSee('data-signature-canvas', false)
            ->assertDontSee('name="typed_signature"', false);

        $this->post(route('public.join.store', $group), $this->payload([
            'signature' => $this->pngDataUri(),
        ]))->assertRedirect(route('public.join.submitted', $group));

        $member = $this->applicant($group, 'grace@example.test');

        $document = Document::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('type', 'signature')
            ->firstOrFail();

        $this->assertSame('image/png', $document->mime_type);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertSame('drawn', data_get($member->metadata, 'electronic_signature.method'));
        $this->assertSame($document->id, data_get($member->metadata, 'electronic_signature.document_id'));
        $this->assertSame(['signature'], array_column(
            data_get($member->metadata, 'application_documents.required', []),
            'key'
        ));
    }

    public function test_typed_signature_requires_consent_and_is_stored_in_the_edms(): void
    {
        Storage::fake('documents');
        Notification::fake();

        $group = $this->group();

        $this->saveRequirements($group, [
            'documents' => [
                'electronic_signature' => ['enabled' => 0, 'required' => 0],
                'typed_signature' => ['enabled' => 1, 'required' => 1],
            ],
        ]);

        $this->get(route('public.join.apply', $group))
            ->assertOk()
            ->assertSee('name="typed_signature"', false)
            ->assertSee('name="typed_signature_consent"', false)
            ->assertDontSee('data-signature-canvas', false);

        $this->post(route('public.join.store', $group), $this->payload([
            'typed_signature' => 'Grace Wanjiku Mwangi',
        ]))->assertSessionHasErrors(['typed_signature_consent']);

        $this->post(route('public.join.store', $group), $this->payload([
            'typed_signature' => 'Grace Wanjiku Mwangi',
            'typed_signature_consent' => '1',
        ]))->assertRedirect(route('public.join.submitted', $group));

        $member = $this->applicant($group, 'grace@example.test');

        $document = Document::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('type', 'signature')
            ->firstOrFail();

        $this->assertSame('text/plain', $document->mime_type);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertStringContainsString(
            'Grace Wanjiku Mwangi',
            Storage::disk('documents')->get($document->path)
        );
        $this->assertSame('typed', data_get($member->metadata, 'electronic_signature.method'));
        $this->assertSame('Grace Wanjiku Mwangi', data_get($member->metadata, 'electronic_signature.name'));
        $this->assertTrue(data_get($member->metadata, 'electronic_signature.consent'));
        $this->assertSame(['signature'], array_column(
            data_get($member->metadata, 'application_documents.required', []),
            'key'
        ));
        $this->assertSame([], app(\App\Services\MemberApplicationService::class)->missingDocuments($member));
    }

    public function test_both_signature_methods_allow_one_method_and_still_require_a_signature(): void
    {
        Storage::fake('documents');
        Notification::fake();

        $group = $this->group();

        $this->saveRequirements($group, [
            'documents' => [
                'electronic_signature' => ['enabled' => 1, 'required' => 1],
                'typed_signature' => ['enabled' => 1, 'required' => 1],
            ],
        ]);

        $this->post(route('public.join.store', $group), $this->payload())
            ->assertSessionHasErrors(['signature', 'typed_signature']);

        $this->post(route('public.join.store', $group), $this->payload([
            'typed_signature' => 'Grace Wanjiku Mwangi',
            'typed_signature_consent' => '1',
        ]))->assertRedirect(route('public.join.submitted', $group));

        $member = $this->applicant($group, 'grace@example.test');

        $this->assertSame(1, Document::withoutGlobalScopes()
            ->where('member_id', $member->id)
            ->where('type', 'signature')
            ->count());
        $this->assertSame([], app(\App\Services\MemberApplicationService::class)->missingDocuments($member));
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function saveRequirements(Group $group, array $input): void
    {
        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $group->id, 'key' => JoinRequirements::SETTING_KEY],
            ['value' => JoinRequirements::normalize($input)]
        );
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

    private function pngDataUri(): string
    {
        return 'data:image/png;base64,'
            .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }
}
