<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Document;
use App\Models\Fine;
use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerLine;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanProduct;
use App\Models\LoanRestructure;
use App\Models\LoginActivity;
use App\Models\Meeting;
use App\Models\MeetingCollection;
use App\Models\Member;
use App\Models\Resolution;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Vote;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_look_up_a_member_by_number_and_see_linked_records(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member, $memberUser] = $this->memberWithRole($group, 'member', 'M0099');

        $product = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Shares',
            'code' => 'SHR',
        ]);

        $account = SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'savings_product_id' => $product->id,
            'account_no' => 'SA-1001',
            'balance' => 125000,
            'available_balance' => 125000,
        ]);

        SavingsTransaction::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'savings_account_id' => $account->id,
            'member_id' => $member->id,
            'type' => 'deposit',
            'direction' => 'credit',
            'amount' => 125000,
            'balance_after' => 125000,
            'transaction_date' => now()->toDateString(),
            'reference' => 'DEP-1001',
        ]);

        Wallet::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'balance' => 5000,
            'available_balance' => 5000,
        ]);

        Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'national_id',
            'title' => 'National ID front',
            'path' => 'documents/front.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'verified',
        ]);

        Fine::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'absenteeism',
            'amount' => 2000,
            'reason' => 'Missed monthly meeting',
        ]);

        LoginActivity::withoutGlobalScopes()->create([
            'user_id' => $memberUser->id,
            'email' => $memberUser->email,
            'ip_address' => '127.0.0.1',
            'successful' => true,
        ]);

        AuditLog::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'user_id' => $admin->id,
            'action' => 'member.created',
            'auditable_type' => Member::class,
            'auditable_id' => $member->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => 'M0099']))
            ->assertOk()
            ->assertSee($member->full_name)
            ->assertSee('M0099')
            ->assertSee('SA-1001')
            ->assertSee('KES 1,250.00')
            ->assertSee('DEP-1001')
            ->assertSee('National ID front')
            ->assertSee('Missed monthly meeting')
            ->assertSee('127.0.0.1')
            ->assertSee('Member Created')
            ->assertSee('Savings accounts')
            ->assertSee('Documents')
            ->assertSee('Activity & audit');
    }

    public function test_lookup_cannot_find_a_member_from_another_group(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');

        $otherGroup = Group::factory()->create();
        [, $otherMemberUser] = $this->memberWithRole($otherGroup, 'member', 'M-OTHER');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => 'M-OTHER']))
            ->assertOk()
            ->assertSee('No member with number')
            ->assertDontSee($otherMemberUser->name);
    }

    public function test_unknown_number_offers_possible_matches(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0099');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => 'M00']))
            ->assertOk()
            ->assertSee('No member with number')
            ->assertSee('Possible matches')
            ->assertSee('M0099');
    }

    public function test_search_lists_close_looking_matches_and_the_field_that_matched(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0098');

        $member->update([
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Doe',
            'workstation' => 'Nairobi CBD',
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['q' => 'Jonh']))
            ->assertOk()
            ->assertSee('Possible matches')
            ->assertSee('Records that look like')
            ->assertSee('John Doe')
            ->assertSee('First name: John');
    }

    public function test_plain_member_cannot_use_the_admin_lookup(): void
    {
        $group = Group::factory()->create();
        [, $memberUser] = $this->memberWithRole($group, 'member', 'M0100');

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => 'M0100']))
            ->assertForbidden();
    }

    public function test_officer_only_sees_sections_their_role_permits(): void
    {
        $group = Group::factory()->create();
        $teller = $this->userWithRole($group, 'teller');
        [$member] = $this->memberWithRole($group, 'member', 'M0101');

        $this->actingAs($teller)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => $member->member_no]))
            ->assertOk()
            ->assertSee('Savings accounts')
            ->assertSee('Teller entries')
            ->assertDontSee('Notification log')
            ->assertDontSee('Consolidated statement')
            ->assertDontSee('Activity & audit');
    }

    public function test_full_profile_and_biometrics_are_visible_without_navigation(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0200');

        $member->update([
            'workstation' => 'Nairobi CBD',
            'metadata' => [
                'biometric_required' => true,
                'electronic_signature' => [
                    'method' => 'typed',
                    'name' => 'Jane Doe',
                    'consent' => true,
                ],
            ],
        ]);

        Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'biometric',
            'title' => 'Fingerprint template',
            'path' => 'edms/groups/'.$group->id.'/members/m0200/fingerprint.png',
            'mime_type' => 'image/png',
            'disk' => 'documents',
            'status' => 'verified',
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => $member->member_no]))
            ->assertOk()
            ->assertSee('Full profile')
            ->assertSee('Workstation / work location')
            ->assertSee('Nairobi CBD')
            ->assertSee('Identity media')
            ->assertSee('Biometrics')
            ->assertSee('Required for this member')
            ->assertSee('Capture on file')
            ->assertSee('Typed name:')
            ->assertSee('Jane Doe')
            ->assertDontSee('Member record sections');
    }

    public function test_nested_application_details_and_id_copies_are_visible_in_360_lookup(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0210');

        $front = Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'national_id',
            'title' => 'ID front copy',
            'path' => 'edms/groups/'.$group->id.'/members/m0210/front.png',
            'mime_type' => 'image/png',
            'disk' => 'documents',
            'status' => 'verified',
        ]);

        $back = Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'national_id_back',
            'title' => 'ID back copy',
            'path' => 'edms/groups/'.$group->id.'/members/m0210/back.png',
            'mime_type' => 'image/png',
            'disk' => 'documents',
            'status' => 'verified',
        ]);

        $member->update([
            'metadata' => [
                'application_source' => 'public website',
                'application_documents' => [
                    'required' => [
                        ['key' => 'national_id_copy', 'label' => 'National ID copy (front)', 'type' => 'national_id'],
                    ],
                    'submitted' => [
                        'national_id_copy' => [
                            'key' => 'national_id_copy',
                            'label' => 'National ID copy (front)',
                            'type' => 'national_id',
                            'document_id' => $front->id,
                        ],
                        'national_id_copy_back' => [
                            'key' => 'national_id_copy_back',
                            'label' => 'National ID copy (back)',
                            'type' => 'national_id_back',
                            'document_id' => $back->id,
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => $member->member_no]))
            ->assertOk()
            ->assertSee('Application details')
            ->assertSee('application_source')
            ->assertSee('public website')
            ->assertSee('application_documents.submitted.national_id_copy.label')
            ->assertSee('National ID copy (front)')
            ->assertSee('National ID copy (back)')
            ->assertSee('Joining documents')
            ->assertSee('ID front copy')
            ->assertSee('ID back copy')
            ->assertSee('National ID copy (back)', 'Identity media');
    }

    public function test_all_linked_member_records_are_visible_without_navigation(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0211');

        $product = LoanProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => '360 loan',
            'code' => 'L360',
            'interest_method' => 'reducing_annuity',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'repayment_frequency' => 'monthly',
            'duration_min' => 1,
            'duration_max' => 12,
            'duration_unit' => 'months',
            'min_amount' => 0,
            'max_amount' => 5_000_000,
        ]);

        $loan = Loan::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'status' => Loan::STATUS_ACTIVE,
            'loan_no' => 'LN-360',
            'requested_amount' => 1_000_000,
            'principal' => 1_000_000,
            'total_repayable' => 1_100_000,
            'outstanding_principal' => 1_000_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'repayment_frequency' => 'monthly',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'interest_method' => 'reducing_annuity',
            'accrual_mode' => 'effective',
            'day_count' => 'actual_365',
        ]);

        LoanApproval::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'loan_id' => $loan->id,
            'step_index' => 1,
            'role_key' => 'loan_officer',
            'action' => 'approved',
            'comments' => 'Approved after review',
        ]);

        LoanRestructure::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'loan_id' => $loan->id,
            'old_terms' => ['duration' => 6],
            'new_terms' => ['duration' => 12],
            'reason' => 'Hardship review',
            'effective_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $meeting = Meeting::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'title' => '360 meeting',
            'scheduled_at' => now(),
        ]);

        MeetingCollection::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'meeting_id' => $meeting->id,
            'member_id' => $member->id,
            'type' => 'savings',
            'amount' => 5_000,
            'reference' => 'MC-360-REF',
        ]);

        SupportTicket::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'subject' => 'Support ticket 360',
        ]);

        Communication::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'channel' => 'sms',
            'body' => 'Communication body 360',
        ]);

        $resolution = Resolution::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'title' => 'Resolution 360',
            'description' => 'Resolution description',
        ]);

        Vote::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'resolution_id' => $resolution->id,
            'member_id' => $member->id,
            'vote' => 'for',
        ]);

        $account = LedgerAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'code' => 'LEDGER360',
            'name' => 'Test ledger account',
            'type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => 'debit',
        ]);

        $entry = JournalEntry::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'entry_no' => 'JE-360',
            'entry_date' => now()->toDateString(),
            'description' => 'Posting 360',
        ]);

        LedgerLine::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'journal_entry_id' => $entry->id,
            'ledger_account_id' => $account->id,
            'member_id' => $member->id,
            'debit' => 5_000,
            'description' => 'Ledger posting 360',
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['member_no' => $member->member_no]))
            ->assertOk()
            ->assertSee('Loan approval history')
            ->assertSee('Approved after review')
            ->assertSee('Loan restructures')
            ->assertSee('Hardship review')
            ->assertSee('Meeting collections')
            ->assertSee('MC-360-REF')
            ->assertSee('CRM & support')
            ->assertSee('Support ticket 360')
            ->assertSee('Communication body 360')
            ->assertSee('Governance')
            ->assertSee('Resolution 360')
            ->assertSee('Accounting')
            ->assertSee('Ledger posting 360');
    }

    public function test_admin_can_preview_identity_media_and_the_view_is_logged(): void
    {
        Storage::fake('documents');

        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0201');

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $path = Storage::disk('documents')->putFileAs(
            'edms/groups/'.$group->id.'/members/m0201',
            UploadedFile::fake()->createWithContent('passport.png', $png),
            'passport.png'
        );

        $document = Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'passport_photo',
            'title' => 'Passport photo',
            'path' => $path,
            'disk' => 'documents',
            'mime_type' => 'image/png',
            'status' => 'verified',
        ]);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup.media', ['member' => $member, 'key' => 'photo']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertDatabaseHas('document_access_logs', [
            'group_id' => $group->id,
            'document_id' => $document->id,
            'user_id' => $admin->id,
            'action' => 'viewed',
        ]);
    }

    public function test_plain_member_cannot_preview_identity_media(): void
    {
        Storage::fake('documents');

        $group = Group::factory()->create();
        [, $memberUser] = $this->memberWithRole($group, 'member', 'M0202');

        $this->actingAs($memberUser)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup.media', ['member' => 1, 'key' => 'photo']))
            ->assertForbidden();
    }

    public function test_global_member_search_is_available_in_the_staff_header(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('Search name, number, ID or anything else');

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup'))
            ->assertOk()
            ->assertSee('name="q"', false)
            ->assertSee('Search name, number, ID or anything else');
    }

    public function test_global_search_matches_name_member_number_id_number_and_phone(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0400');

        $member->update([
            'first_name' => 'Jane',
            'last_name' => 'Wanjiku',
            'national_id' => '11223344',
            'phone' => '+254712345678',
        ]);

        $this->actingAs($admin)->withSession(['active_group_id' => $group->id]);

        foreach (['Wanjiku', 'M0400', '11223344', '712345678'] as $term) {
            $this->get(route('members.lookup', ['q' => $term]))
                ->assertRedirect(route('members.lookup', ['member_no' => $member->member_no]));
        }
    }

    public function test_global_search_matches_related_savings_and_document_records(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$member] = $this->memberWithRole($group, 'member', 'M0401');

        $product = SavingsProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Global shares',
            'code' => 'GSH',
        ]);

        SavingsAccount::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'savings_product_id' => $product->id,
            'account_no' => 'SA-GLOBAL-77',
        ]);

        Document::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'type' => 'national_id',
            'title' => 'ID scan GLOBALDOC',
            'path' => 'documents/global.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($admin)->withSession(['active_group_id' => $group->id]);

        foreach (['SA-GLOBAL-77', 'GLOBALDOC'] as $term) {
            $this->get(route('members.lookup', ['q' => $term]))
                ->assertRedirect(route('members.lookup', ['member_no' => $member->member_no]));
        }
    }

    public function test_global_search_lists_multiple_matches_with_360_links(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        [$first] = $this->memberWithRole($group, 'member', 'M0402');
        [$second] = $this->memberWithRole($group, 'member', 'M0403');

        $first->update(['last_name' => 'Otieno', 'workstation' => 'Nairobi CBD']);
        $second->update(['last_name' => 'Otieno', 'workstation' => 'Kisumu']);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['q' => 'Otieno']))
            ->assertOk()
            ->assertSee('Matching members')
            ->assertSee($first->full_name)
            ->assertSee($second->full_name)
            ->assertSee(route('members.lookup', ['member_no' => 'M0402']), false)
            ->assertSee(route('members.lookup', ['member_no' => 'M0403']), false);
    }

    public function test_global_search_is_scoped_to_the_active_group(): void
    {
        $group = Group::factory()->create();
        $admin = $this->userWithRole($group, 'group_admin');
        $otherGroup = Group::factory()->create();
        [$otherMember] = $this->memberWithRole($otherGroup, 'member', 'M-OTHER-GLOBAL');

        $otherMember->update(['workstation' => 'Mombasa Island Office']);

        $this->actingAs($admin)
            ->withSession(['active_group_id' => $group->id])
            ->get(route('members.lookup', ['q' => 'Mombasa Island Office']))
            ->assertOk()
            ->assertSee('No members found')
            ->assertDontSee($otherMember->full_name);
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
                'membership_category' => 'ordinary',
            ]);

        return [$member, $user];
    }

    private function userWithRole(Group $group, string $roleKey): User
    {
        return $this->memberWithRole($group, $roleKey, 'M-STAFF-'.strtoupper($roleKey))[1];
    }
}
