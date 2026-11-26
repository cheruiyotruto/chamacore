<?php

namespace Tests\Feature;

use App\Contracts\PushGateway;
use App\Contracts\SmsGateway;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\DeviceToken;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Guarantor;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Notifications\ChamaNotification;
use App\Services\LedgerService;
use App\Services\LoanOriginationService;
use App\Support\GuaranteeSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuaranteeSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    public function test_accepting_records_the_decision_without_storing_a_signature(): void
    {
        $fixture = $this->loanFixture();

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.guarantees.respond', $fixture['guarantee']), [
                'decision' => 'accept',
                'note' => 'I agree to guarantee this loan.',
            ])
            ->assertRedirect(route('portal.guarantees.sign', $fixture['guarantee']))
            ->assertSessionHas('status', 'Guarantee accepted. Add your electronic signature to complete it.');

        $this->assertSame(Guarantor::STATUS_ACCEPTED, $fixture['guarantee']->fresh()->status);
        $this->assertSame('I agree to guarantee this loan.', $fixture['guarantee']->fresh()->response_note);
        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));
        $this->assertSame(0, Communication::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('subject', 'like', 'Guarantee signed%')
            ->count());
    }

    public function test_the_signature_page_is_a_separate_step_after_acceptance(): void
    {
        $fixture = $this->loanFixture();

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->get(route('portal.guarantees.sign', $fixture['guarantee']))
            ->assertNotFound();

        $this->post(route('portal.guarantees.respond', $fixture['guarantee']), [
            'decision' => 'accept',
        ])->assertRedirect(route('portal.guarantees.sign', $fixture['guarantee']));

        $this->get(route('portal.guarantees.sign', $fixture['guarantee']))
            ->assertOk()
            ->assertSee('Sign guarantee')
            ->assertSee('data-signature-canvas', false);
    }

    public function test_signing_after_acceptance_stores_the_signature_in_the_edms_and_crm_activity(): void
    {
        $fixture = $this->loanFixture();
        $this->acceptGuarantee($fixture);

        $this->post(route('portal.guarantees.sign.store', $fixture['guarantee']), [
            'signature' => $this->pngDataUri(),
        ])->assertRedirect(route('portal.loans.index'));

        $document = $this->signatureDocument($fixture['guarantee']);
        $this->assertSame($fixture['guarantor']->id, (int) $document->member_id);
        $this->assertSame('guarantee_signature', $document->type);
        $this->assertSame('image/png', $document->mime_type);
        Storage::disk('documents')->assertExists($document->path);

        $communication = Communication::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('subject', 'like', 'Guarantee signed%')
            ->firstOrFail();

        $this->assertSame('Guarantee signed · '.$fixture['loan']->loan_no, $communication->subject);
        $this->assertSame($document->id, $communication->metadata['document_id']);
        $this->assertSame($fixture['guarantee']->id, $communication->metadata['guarantor_id']);

        $audit = AuditLog::where('action', 'guarantee.signed')->latest('id')->firstOrFail();
        $this->assertSame($document->id, $audit->new_values['document_id']);
    }

    public function test_a_guarantee_cannot_be_signed_before_it_is_accepted(): void
    {
        $fixture = $this->loanFixture();

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.guarantees.sign.store', $fixture['guarantee']), [
                'signature' => $this->pngDataUri(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(Guarantor::STATUS_PENDING, $fixture['guarantee']->fresh()->status);
        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));
    }

    public function test_an_invalid_signature_is_rejected_before_it_is_stored(): void
    {
        $fixture = $this->loanFixture();
        $this->acceptGuarantee($fixture);

        $this->post(route('portal.guarantees.sign.store', $fixture['guarantee']), [
            'signature' => 'data:image/png;base64,not-base64!!',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('signature');

        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));
    }

    public function test_another_member_cannot_sign_someone_elses_guarantee(): void
    {
        $fixture = $this->loanFixture();
        $this->acceptGuarantee($fixture);

        $this->actingAs($fixture['borrowerUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.guarantees.sign.store', $fixture['guarantee']), [
                'signature' => $this->pngDataUri(),
            ])
            ->assertForbidden();

        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));
    }

    public function test_a_guarantee_cannot_be_signed_twice(): void
    {
        $fixture = $this->loanFixture();
        $this->acceptGuarantee($fixture);

        $this->post(route('portal.guarantees.sign.store', $fixture['guarantee']), [
            'signature' => $this->pngDataUri(),
        ])->assertRedirect(route('portal.loans.index'));

        $this->post(route('portal.guarantees.sign.store', $fixture['guarantee']), [
            'signature' => $this->pngDataUri(),
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, Document::withoutGlobalScopes()
            ->where('documentable_type', Guarantor::class)
            ->where('documentable_id', $fixture['guarantee']->id)
            ->where('type', 'guarantee_signature')
            ->count());
    }

    public function test_a_guarantor_can_reject_without_signing(): void
    {
        $fixture = $this->loanFixture();

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.guarantees.respond', $fixture['guarantee']), [
                'decision' => 'reject',
                'note' => 'I cannot guarantee this amount.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Guarantee response recorded.');

        $this->assertSame(Guarantor::STATUS_REJECTED, $fixture['guarantee']->fresh()->status);
        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));
    }

    public function test_an_admin_can_make_the_signing_step_optional(): void
    {
        $fixture = $this->loanFixture(['require_signature' => false]);

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->from(route('portal.loans.index'))
            ->post(route('portal.guarantees.respond', $fixture['guarantee']), [
                'decision' => 'accept',
            ])
            ->assertRedirect(route('portal.loans.index'))
            ->assertSessionHas('status', 'Guarantee response recorded.');

        $this->assertSame(Guarantor::STATUS_ACCEPTED, $fixture['guarantee']->fresh()->status);
        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));

        $this->get(route('portal.loans.index'))
            ->assertOk()
            ->assertDontSee('Awaiting your signature');
    }

    public function test_settings_exposes_the_signature_and_channel_toggles(): void
    {
        $fixture = $this->loanFixture();

        $adminRole = Role::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('key', 'group_admin')
            ->firstOrFail();
        $fixture['guarantor']->update(['role_id' => $adminRole->id]);

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Require a signature on acceptance')
            ->assertSee('name="require_signature"', false)
            ->assertSee('Guarantee request channels')
            ->assertSee('name="notification_channels[]"', false);
    }

    public function test_a_group_admin_can_choose_which_notification_channels_are_enabled(): void
    {
        $fixture = $this->loanFixture();

        $adminRole = Role::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('key', 'group_admin')
            ->firstOrFail();
        $fixture['guarantor']->update(['role_id' => $adminRole->id]);

        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->put(route('settings.guarantees.update'), [
                'new_guarantees' => 'principal',
                'existing_commitments' => 'principal',
                'require_signature' => '1',
                'notification_channels' => ['', 'sms', 'email'],
            ])
            ->assertRedirect();

        $settings = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $fixture['group']->id)
            ->where('key', GuaranteeSettings::SETTING_KEY)
            ->firstOrFail()
            ->value;

        $this->assertSame(['sms', 'email'], $settings['notification_channels']);
    }

    public function test_the_guarantor_request_links_to_the_portal_and_only_uses_enabled_channels(): void
    {
        Notification::fake();

        $sms = new class implements SmsGateway
        {
            public array $messages = [];

            public function send(string $recipient, string $message): array
            {
                $this->messages[] = $message;

                return ['success' => true, 'reference' => 'test', 'error' => null];
            }
        };
        $push = new class implements PushGateway
        {
            public array $messages = [];

            public function send(array $tokens, string $title, string $body, array $data = []): array
            {
                $this->messages[] = $body;

                return ['success' => true, 'reference' => 'test', 'error' => null];
            }
        };
        $this->app->instance(SmsGateway::class, $sms);
        $this->app->instance(PushGateway::class, $push);

        $fixture = $this->loanFixture([
            'notification_channels' => ['in_app', 'push'],
        ]);

        // The loan application also notifies the borrower; ignore that traffic.
        $sms->messages = [];
        $push->messages = [];

        DeviceToken::create([
            'user_id' => $fixture['guarantorUser']->id,
            'token' => 'test-device-token',
            'platform' => 'test',
        ]);

        // Push devices are normally registered before the request is sent.
        app(\App\Services\NotificationService::class)->send($fixture['guarantor'], 'guarantor_request', [
            'borrower_name' => $fixture['borrower']->full_name,
            'amount' => 'KES 1,000.00',
            'loan_no' => $fixture['loan']->loan_no,
            'portal_url' => route('portal.loans.index', absolute: true).'#guarantee-'.$fixture['guarantee']->id,
        ], ['in_app', 'push']);

        $url = route('portal.loans.index', absolute: true).'#guarantee-'.$fixture['guarantee']->id;

        Notification::assertSentTo($fixture['guarantorUser'], ChamaNotification::class, function (ChamaNotification $notification) use ($url) {
            return str_contains($notification->body, $url)
                && $notification->actionUrl === $url
                && $notification->sendDatabase
                && ! $notification->sendMail;
        });

        $this->assertSame([], $sms->messages);
        $this->assertCount(1, $push->messages);
        $this->assertStringContainsString($url, $push->messages[0]);

        $channels = NotificationLog::withoutGlobalScopes()
            ->where('member_id', $fixture['guarantor']->id)
            ->where('template_key', 'guarantor_request')
            ->pluck('channel')
            ->all();

        $this->assertEqualsCanonicalizing(['in_app', 'push'], array_values(array_unique($channels)));
    }

    public function test_the_api_accepts_then_signs_as_separate_requests(): void
    {
        $fixture = $this->loanFixture();

        Sanctum::actingAs($fixture['guarantorUser'], ['*']);

        $response = $this->withHeader('X-Group-Id', (string) $fixture['group']->id)
            ->postJson(route('api.loans.guarantees.respond', $fixture['guarantee']), [
                'decision' => 'accept',
            ])
            ->assertOk();

        $response->assertJson([
            'signature_required' => true,
            'sign_url' => route('api.loans.guarantees.sign', $fixture['guarantee']),
        ]);

        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));

        $this->postJson(route('api.loans.guarantees.sign', $fixture['guarantee']), [
            'signature' => $this->pngDataUri(),
        ])->assertOk()->assertJsonStructure(['message', 'document_id']);

        $this->assertNotNull($this->signatureDocumentOrNull($fixture['guarantee']));
    }

    public function test_the_api_rejects_a_missing_signature_on_the_sign_endpoint(): void
    {
        $fixture = $this->loanFixture();
        $this->acceptGuarantee($fixture);

        Sanctum::actingAs($fixture['guarantorUser'], ['*']);

        $this->withHeader('X-Group-Id', (string) $fixture['group']->id)
            ->postJson(route('api.loans.guarantees.sign', $fixture['guarantee']), [])
            ->assertStatus(422);

        $this->assertNull($this->signatureDocumentOrNull($fixture['guarantee']));
    }

    public function test_the_api_guarantee_payload_exposes_the_portal_and_signature_links(): void
    {
        $fixture = $this->loanFixture();

        Sanctum::actingAs($fixture['guarantorUser'], ['*']);

        $response = $this->withHeader('X-Group-Id', (string) $fixture['group']->id)
            ->getJson(route('api.loans.guarantees'))
            ->assertOk();

        $response->assertJsonPath('data.data.0.portal_url', route('portal.loans.index', absolute: false).'#guarantee-'.$fixture['guarantee']->id);
        $response->assertJsonPath('data.data.0.signature_url', null);
    }

    private function acceptGuarantee(array $fixture): array
    {
        $this->actingAs($fixture['guarantorUser'])
            ->withSession(['active_group_id' => $fixture['group']->id])
            ->post(route('portal.guarantees.respond', $fixture['guarantee']), [
                'decision' => 'accept',
            ])
            ->assertRedirect(route('portal.guarantees.sign', $fixture['guarantee']));

        return $fixture;
    }

    private function signatureDocument(Guarantor $guarantor): Document
    {
        return Document::withoutGlobalScopes()
            ->where('documentable_type', Guarantor::class)
            ->where('documentable_id', $guarantor->id)
            ->where('type', 'guarantee_signature')
            ->firstOrFail();
    }

    private function signatureDocumentOrNull(Guarantor $guarantor): ?Document
    {
        return Document::withoutGlobalScopes()
            ->where('documentable_type', Guarantor::class)
            ->where('documentable_id', $guarantor->id)
            ->where('type', 'guarantee_signature')
            ->first();
    }

    private function pngDataUri(): string
    {
        return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 64));
    }

    /**
     * @param  array<string, mixed>  $guaranteeSettings
     * @return array{
     *     group: Group,
     *     borrower: Member,
     *     borrowerUser: User,
     *     guarantor: Member,
     *     guarantorUser: User,
     *     loan: \App\Models\Loan,
     *     guarantee: Guarantor
     * }
     */
    private function loanFixture(array $guaranteeSettings = []): array
    {
        $group = Group::factory()->create([
            'status' => 'active',
            'subscription_status' => 'active',
        ]);

        app(RoleSeeder::class)->seedForGroup($group);
        app(LedgerService::class)->bootstrap($group);

        $role = Role::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', 'member')
            ->firstOrFail();

        $borrowerUser = User::factory()->create();
        $guarantorUser = User::factory()->create();

        $borrower = Member::factory()->forGroup($group)->forUser($borrowerUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);
        $guarantor = Member::factory()->forGroup($group)->forUser($guarantorUser)->create([
            'role_id' => $role->id,
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);

        $savingsProduct = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Regular savings',
            'code' => 'REG-'.substr(md5('sig-reg-'.$group->id), 0, 8),
            'type' => 'voluntary',
            'qualifies_for_loan' => true,
            'is_active' => true,
        ]);

        foreach ([$borrower, $guarantor] as $index => $member) {
            SavingsAccount::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'member_id' => $member->id,
                'savings_product_id' => $savingsProduct->id,
                'account_no' => 'SAV-SIG-'.$group->id.'-'.$index,
                'balance' => 1_000_000,
                'available_balance' => 1_000_000,
                'status' => 'active',
            ]);
        }

        app(LedgerService::class)->post($group, 'Test funding', [
            ['code' => LedgerService::CASH, 'debit' => 5_000_000],
            ['code' => LedgerService::GROUP_EQUITY, 'credit' => 5_000_000],
        ]);

        $product = LoanProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Business loan',
            'code' => 'BIZ-SIG-'.substr(md5('sig-loan-'.$group->id), 0, 8),
            'category' => 'business',
            'interest_method' => 'flat',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'accrual_mode' => 'simple',
            'day_count' => 'actual_365',
            'repayment_frequency' => 'monthly',
            'duration_min' => 1,
            'duration_max' => 24,
            'duration_unit' => 'months',
            'min_amount' => 100_000,
            'max_amount' => 5_000_000,
            'multiplier_bps' => 30_000,
            'guarantee_multiplier_bps' => 30_000,
            'share_weight_bps' => 10_000,
            'requires_guarantors' => true,
            'min_guarantors' => 1,
            'guarantor_coverage_bps' => 10_000,
            'processing_fee_bps' => 150,
            'insurance_fee_bps' => 25,
            'min_membership_days' => 0,
            'max_concurrent_loans' => 2,
            'requires_no_arrears' => false,
            'is_active' => true,
        ]);

        if ($guaranteeSettings !== []) {
            GroupSetting::withoutGlobalScopes()->updateOrCreate(
                ['group_id' => $group->id, 'key' => GuaranteeSettings::SETTING_KEY],
                ['value' => GuaranteeSettings::normalize($guaranteeSettings)]
            );
        }

        $loan = app(LoanOriginationService::class)->apply($borrower, $product, [
            'amount' => 200_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'guarantors' => [['member_id' => $guarantor->id]],
        ], $borrowerUser);

        return [
            'group' => $group,
            'borrower' => $borrower,
            'borrowerUser' => $borrowerUser,
            'guarantor' => $guarantor,
            'guarantorUser' => $guarantorUser,
            'loan' => $loan,
            'guarantee' => $loan->guarantors()->firstOrFail(),
        ];
    }
}
