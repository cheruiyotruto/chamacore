<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Communication;
use App\Models\Document;
use App\Models\Fine;
use App\Models\Guarantor;
use App\Models\Investment;
use App\Models\InvestmentTransaction;
use App\Models\Lead;
use App\Models\LedgerLine;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanPayment;
use App\Models\LoanPenaltyCharge;
use App\Models\LoanRestructure;
use App\Models\LoanSchedule;
use App\Models\LoginActivity;
use App\Models\MeetingCollection;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundParticipant;
use App\Models\MerryGoRoundPayout;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\NextOfKin;
use App\Models\NotificationLog;
use App\Models\Payout;
use App\Models\PaymentIntent;
use App\Models\ReminderDelivery;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\SupportTicket;
use App\Models\TellerEntry;
use App\Models\Vote;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WelfareClaim;
use App\Models\WelfareContribution;
use App\Support\JoinRequirements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the all-round view of one member for group staff.
 *
 * Every query is explicitly constrained to the member's group and the correct
 * member-key column. This keeps the lookup safe even when the active tenant
 * context changes between requests or a related table uses a non-standard key.
 */
final class MemberLookupService
{
    /**
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function lookup(Member $member, array $can, string $currency): array
    {
        $member->loadMissing(['user', 'role', 'approver', 'group']);

        $groupId = (int) $member->group_id;
        $memberId = (int) $member->id;

        $query = function (string $class, string $memberColumn = 'member_id') use ($groupId, $memberId): Builder {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
            return $class::withoutGlobalScopes()
                ->where('group_id', $groupId)
                ->where($memberColumn, $memberId)
                ->orderByDesc('id');
        };

        $savingsAccounts = $query(SavingsAccount::class)->with('product')->get();
        $savingsTransactions = $query(SavingsTransaction::class)->with('account.product')->limit(50)->get();

        $loans = $query(Loan::class)->with('product')->get();
        $loanIds = $loans->pluck('id')->all();
        $loanRelated = function (string $class) use ($groupId, $loanIds): Builder {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
            return $class::withoutGlobalScopes()
                ->where('group_id', $groupId)
                ->whereIn('loan_id', $loanIds)
                ->orderByDesc('id');
        };
        $schedules = $loanIds
            ? LoanSchedule::withoutGlobalScopes()->where('group_id', $groupId)->whereIn('loan_id', $loanIds)->orderBy('due_date')->limit(100)->get()
            : collect();
        $loanPayments = $query(LoanPayment::class)->with('loan')->limit(50)->get();
        $loanApprovals = $loanRelated(LoanApproval::class)->with(['loan', 'actor'])->limit(50)->get();
        $loanPenalties = $loanRelated(LoanPenaltyCharge::class)->with(['loan', 'installment'])->limit(50)->get();
        $loanRestructures = $loanRelated(LoanRestructure::class)->with('loan')->limit(50)->get();
        $guarantees = $query(Guarantor::class, 'guarantor_member_id')->with('loan')->get();

        $wallet = $query(Wallet::class)->first();
        $walletTransactions = $query(WalletTransaction::class)->limit(50)->get();

        $investments = $query(Investment::class)->with('product')->get();
        $investmentTransactions = $query(InvestmentTransaction::class)->limit(50)->get();

        $mgrParticipants = $query(MerryGoRoundParticipant::class)->with('cycle')->get();
        $mgrContributions = $query(MerryGoRoundContribution::class)->with('cycle')->limit(50)->get();
        $mgrPayouts = $query(MerryGoRoundPayout::class)->with('cycle')->limit(50)->get();

        $paymentIntents = $query(PaymentIntent::class)->limit(50)->get();
        $bills = $query(Bill::class)->limit(50)->get();
        $billPayments = $query(BillPayment::class)->with('bill')->limit(50)->get();
        $payouts = $query(Payout::class)->limit(50)->get();
        $tellerEntries = $query(TellerEntry::class)->with('creator')->limit(50)->get();

        $attendance = $query(\App\Models\MeetingAttendance::class)->with('meeting')->limit(50)->get();
        $meetingCollections = $query(MeetingCollection::class)->with(['meeting', 'product'])->limit(50)->get();
        $fines = $query(Fine::class)->with('meeting')->limit(50)->get();
        $welfareContributions = $query(WelfareContribution::class)->with('fund')->limit(50)->get();
        $welfareClaims = $query(WelfareClaim::class)->with('fund')->limit(50)->get();

        $allDocuments = $query(Document::class)->with(['uploader', 'verifier'])->get();
        $documents = $allDocuments->take(50);
        $identityMedia = $this->identityMedia($member, $allDocuments);
        $notifications = $query(NotificationLog::class)->limit(50)->get();
        $reminders = $query(ReminderDelivery::class)->with('rule')->limit(50)->get();

        $tickets = $query(SupportTicket::class)->limit(50)->get();
        $communications = $query(Communication::class)->limit(50)->get();
        $leads = $query(Lead::class, 'converted_member_id')->limit(50)->get();
        $votes = $query(Vote::class)->with('resolution')->limit(50)->get();
        $ledgerLines = $query(LedgerLine::class)->with(['journalEntry', 'account'])->limit(50)->get();

        $kin = $query(NextOfKin::class)->get();
        $beneficiaries = $query(Beneficiary::class)->get();
        $invitations = MemberInvitation::withoutGlobalScopes()
            ->where('group_id', $groupId)
            ->where(function ($inner) use ($memberId) {
                $inner->where('inviter_member_id', $memberId)
                    ->orWhere('accepted_member_id', $memberId);
            })
            ->latest('id')
            ->get();

        $audit = AuditLog::withoutGlobalScopes()
            ->where('group_id', $groupId)
            ->where(function ($inner) use ($member, $memberId) {
                $inner->where('auditable_type', Member::class)
                    ->where('auditable_id', $memberId);

                if ($member->user_id) {
                    $inner->orWhere('user_id', $member->user_id);
                }
            })
            ->latest('id')
            ->limit(50)
            ->get();

        $logins = $member->user_id
            ? LoginActivity::withoutGlobalScopes()->where('user_id', $member->user_id)->latest('id')->limit(50)->get()
            : collect();

        $activeStatuses = ['disbursed', 'active', 'restructured'];
        $activeLoans = $loans->filter(fn (Loan $loan) => in_array($loan->status, $activeStatuses, true));

        $summary = [
            'savings_accounts' => $savingsAccounts->count(),
            'savings_total' => (int) $savingsAccounts->sum('balance'),
            'active_loans' => $activeLoans->count(),
            'loan_balance' => (int) $activeLoans->sum(fn (Loan $loan) => $loan->outstandingTotal()),
            'wallet_balance' => (int) ($wallet?->balance ?? 0),
            'investment_value' => (int) $investments->sum(fn (Investment $investment) => $investment->totalValue()),
            'guaranteed_total' => (int) $guarantees->sum('guaranteed_amount'),
            'fine_outstanding' => (int) $fines->sum(fn (Fine $fine) => $fine->outstanding()),
            'mgr_contributed' => (int) $mgrParticipants->sum('total_contributed'),
            'mgr_received' => (int) $mgrParticipants->sum('total_received'),
            'document_count' => $allDocuments->count(),
        ];

        $result = [
            'currency' => $currency,
            'summary' => $summary,
            'identity' => [
                'member' => $member,
                'account' => [
                    'email' => $member->user?->email,
                    'email_verified_at' => $member->user?->email_verified_at,
                    'phone_verified_at' => $member->user?->phone_verified_at,
                    'last_login_at' => $member->user?->last_login_at,
                    'status' => $member->user?->status,
                ],
                'kra_pin' => data_get($member->metadata ?? [], 'kra_pin_number'),
                'id_document_path' => $member->id_document_path,
                'metadata' => $this->flattenMetadata($member->metadata ?? []),
                'next_of_kin' => $this->map($kin, fn ($row) => [
                    'name' => $row->name,
                    'relationship' => $row->relationship,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'id_number' => $row->id_number,
                ]),
                'beneficiaries' => $this->map($beneficiaries, fn ($row) => [
                    'name' => $row->name,
                    'relationship' => $row->relationship,
                    'phone' => $row->phone,
                    'allocation_percent' => $row->allocation_percent,
                    'is_primary' => (bool) $row->is_primary,
                ]),
                'invitations' => $this->map($invitations, fn ($row) => [
                    'created_at' => $row->created_at,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'status' => $row->status,
                    'sent_at' => $row->sent_at,
                    'accepted_at' => $row->accepted_at,
                ]),
            ],
            'identity_media' => $identityMedia,
            'savings' => [
                'accounts' => $this->map($savingsAccounts, fn ($row) => [
                    'account_no' => $row->account_no,
                    'product' => $row->product?->name,
                    'balance' => (int) $row->balance,
                    'available_balance' => (int) $row->available_balance,
                    'pledged_amount' => (int) $row->pledged_amount,
                    'status' => $row->status,
                    'opened_at' => $row->opened_at,
                ]),
                'transactions' => $this->map($savingsTransactions, fn ($row) => [
                    'date' => $row->transaction_date,
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'direction' => $row->direction === 'credit' ? 'credited' : 'debited',
                    'amount' => (int) $row->amount,
                    'balance_after' => (int) $row->balance_after,
                    'reference' => $row->reference,
                    'description' => $row->description ?: $row->account?->product?->name,
                ]),
            ],
            'loans' => [
                'loans' => $this->map($loans, fn ($row) => [
                    'loan_no' => $row->loan_no,
                    'product' => $row->product?->name,
                    'status' => $row->status,
                    'principal' => (int) $row->principal,
                    'outstanding' => $row->outstandingTotal(),
                    'maturity_date' => $row->maturity_date,
                ]),
                'schedules' => $this->map($schedules, fn ($row) => [
                    'loan_no' => $row->loan?->loan_no,
                    'installment' => $row->installment_no,
                    'due_date' => $row->due_date,
                    'total_due' => $row->totalDue(),
                    'total_paid' => $row->totalPaid(),
                    'status' => $row->status,
                ]),
                'payments' => $this->map($loanPayments, fn ($row) => [
                    'paid_at' => $row->paid_at ?: $row->created_at,
                    'loan_no' => $row->loan?->loan_no,
                    'amount' => (int) $row->amount,
                    'method' => $row->method,
                    'reference' => $row->reference,
                    'status' => $row->status,
                ]),
                'guarantees' => $this->map($guarantees, fn ($row) => [
                    'loan_no' => $row->loan?->loan_no,
                    'guaranteed_amount' => (int) $row->guaranteed_amount,
                    'status' => $row->status,
                ]),
                'approvals' => $this->map($loanApprovals, fn ($row) => [
                    'loan_no' => $row->loan?->loan_no,
                    'step' => (int) $row->step_index,
                    'role' => ucwords(str_replace('_', ' ', (string) $row->role_key)),
                    'action' => ucwords(str_replace('_', ' ', (string) $row->action)),
                    'comments' => $row->comments,
                    'actor' => $row->actor?->name,
                    'acted_at' => $row->acted_at,
                ]),
                'penalties' => $this->map($loanPenalties, fn ($row) => [
                    'loan_no' => $row->loan?->loan_no,
                    'installment' => $row->installment?->installment_no,
                    'amount' => (int) $row->amount,
                    'period_start' => $row->period_start,
                    'period_end' => $row->period_end,
                    'status' => $row->status,
                    'paid_at' => $row->paid_at,
                ]),
                'restructures' => $this->map($loanRestructures, fn ($row) => [
                    'loan_no' => $row->loan?->loan_no,
                    'effective_date' => $row->effective_date,
                    'status' => $row->status,
                    'reason' => $row->reason,
                    'old_terms' => $this->termsSummary($row->old_terms),
                    'new_terms' => $this->termsSummary($row->new_terms),
                ]),
            ],
            'wallet' => [
                'account' => $wallet ? [
                    'balance' => (int) $wallet->balance,
                    'available_balance' => (int) $wallet->available_balance,
                    'status' => $wallet->status,
                ] : null,
                'transactions' => $this->map($walletTransactions, fn ($row) => [
                    'date' => $row->completed_at ?: $row->created_at,
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'direction' => $row->direction === 'credit' ? 'credited' : 'debited',
                    'amount' => (int) $row->amount,
                    'balance_after' => (int) $row->balance_after,
                    'status' => $row->status,
                    'reference' => $row->reference,
                    'description' => $row->description,
                ]),
            ],
            'investments' => [
                'accounts' => $this->map($investments, fn ($row) => [
                    'reference_no' => $row->reference_no,
                    'product' => $row->product?->name,
                    'status' => $row->status,
                    'principal' => (int) $row->principal,
                    'total_value' => $row->totalValue(),
                    'maturity_date' => $row->maturity_date,
                ]),
                'transactions' => $this->map($investmentTransactions, fn ($row) => [
                    'date' => $row->transaction_date,
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'direction' => $row->direction === 'credit' ? 'credited' : 'debited',
                    'amount' => (int) $row->amount,
                    'balance_after' => (int) $row->balance_after,
                    'reference' => $row->reference,
                    'description' => $row->description,
                ]),
            ],
            'mgr' => [
                'participations' => $this->map($mgrParticipants, fn ($row) => [
                    'cycle' => $row->cycle?->name,
                    'position' => $row->position,
                    'payout_date' => $row->payout_date,
                    'status' => $row->status,
                    'total_contributed' => (int) $row->total_contributed,
                    'total_received' => (int) $row->total_received,
                ]),
                'contributions' => $this->map($mgrContributions, fn ($row) => [
                    'cycle' => $row->cycle?->name,
                    'due_date' => $row->due_date,
                    'paid_at' => $row->paid_at,
                    'status' => $row->status,
                    'amount' => (int) $row->amount,
                    'penalty_amount' => (int) $row->penalty_amount,
                    'reference' => $row->reference,
                ]),
                'payouts' => $this->map($mgrPayouts, fn ($row) => [
                    'cycle' => $row->cycle?->name,
                    'scheduled_date' => $row->scheduled_date,
                    'paid_at' => $row->paid_at,
                    'status' => $row->status,
                    'amount' => (int) $row->amount,
                    'reference' => $row->reference,
                ]),
            ],
            'payments' => [
                'intents' => $this->map($paymentIntents, fn ($row) => [
                    'created_at' => $row->created_at,
                    'purpose' => ucwords(str_replace('_', ' ', (string) $row->purpose)),
                    'provider' => $row->provider,
                    'phone' => $row->phone,
                    'amount' => (int) $row->amount,
                    'status' => $row->status,
                    'provider_reference' => $row->provider_reference,
                    'failure_reason' => $row->failure_reason,
                ]),
                'bills' => $this->map($bills, fn ($row) => [
                    'bill_no' => $row->bill_no,
                    'payee' => $row->payee_name,
                    'amount' => (int) $row->amount,
                    'amount_paid' => (int) $row->amount_paid,
                    'status' => $row->status,
                    'due_date' => $row->due_date,
                ]),
                'bill_payments' => $this->map($billPayments, fn ($row) => [
                    'paid_at' => $row->paid_at ?: $row->created_at,
                    'bill_no' => $row->bill?->bill_no,
                    'amount' => (int) $row->amount,
                    'method' => $row->method,
                    'reference' => $row->reference,
                ]),
                'payouts' => $this->map($payouts, fn ($row) => [
                    'paid_at' => $row->paid_at ?: $row->created_at,
                    'source' => ucwords(str_replace('_', ' ', (string) $row->source)),
                    'amount' => (int) $row->amount,
                    'net_amount' => (int) $row->net_amount,
                    'status' => $row->status,
                    'provider_reference' => $row->provider_reference,
                    'failure_reason' => $row->failure_reason,
                ]),
            ],
            'teller' => [
                'entries' => $this->map($tellerEntries, fn ($row) => [
                    'created_at' => $row->created_at,
                    'receipt_no' => $row->receipt_no,
                    'category' => ucwords(str_replace('_', ' ', (string) $row->category)),
                    'direction' => $row->direction,
                    'amount' => (int) $row->amount,
                    'charge_amount' => (int) $row->charge_amount,
                    'method' => $row->method,
                    'reference' => $row->reference,
                    'recorded_by' => $row->creator?->name,
                    'narration' => $row->narration,
                ]),
            ],
            'meetings' => [
                'attendance' => $this->map($attendance, fn ($row) => [
                    'meeting' => $row->meeting?->title,
                    'date' => $row->meeting?->scheduled_at,
                    'status' => $row->status,
                    'fine_amount' => (int) $row->fine_amount,
                    'notes' => $row->notes,
                ]),
                'fines' => $this->map($fines, fn ($row) => [
                    'meeting' => $row->meeting?->title,
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'amount' => (int) $row->amount,
                    'amount_paid' => (int) $row->amount_paid,
                    'outstanding' => $row->outstanding(),
                    'status' => $row->status,
                    'due_date' => $row->due_date,
                    'reason' => $row->reason,
                ]),
                'collections' => $this->map($meetingCollections, fn ($row) => [
                    'meeting' => $row->meeting?->title,
                    'date' => $row->meeting?->scheduled_at,
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'product' => $row->product?->name,
                    'amount' => (int) $row->amount,
                    'reference' => $row->reference,
                ]),
            ],
            'welfare' => [
                'contributions' => $this->map($welfareContributions, fn ($row) => [
                    'date' => $row->contribution_date,
                    'fund' => $row->fund?->name,
                    'amount' => (int) $row->amount,
                    'reference' => $row->reference,
                ]),
                'claims' => $this->map($welfareClaims, fn ($row) => [
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'fund' => $row->fund?->name,
                    'amount' => (int) $row->amount,
                    'status' => $row->status,
                    'reason' => $row->reason,
                    'disbursed_at' => $row->disbursed_at,
                ]),
            ],
            'documents' => [
                'documents' => $this->map($documents, fn ($row) => [
                    'title' => $row->title,
                    'type' => ucwords(str_replace('_', ' ', (string) $row->type)),
                    'status' => $row->status,
                    'verified_at' => $row->verified_at,
                    'mime_type' => $row->mime_type,
                    'size' => (int) $row->size,
                    'uploaded_by' => $row->uploader?->name,
                ]),
                'missing' => app(MemberEdmsService::class)->missingRequiredDocuments($member),
            ],
            'notifications' => [
                'logs' => $this->map($notifications, fn ($row) => [
                    'date' => $row->sent_at ?: $row->created_at,
                    'channel' => strtoupper((string) $row->channel),
                    'recipient' => $row->recipient,
                    'template' => $row->template_key,
                    'status' => $row->status,
                    'error' => $row->error,
                ]),
                'reminders' => $this->map($reminders, fn ($row) => [
                    'sent_on' => $row->sent_on,
                    'rule' => $row->rule?->name,
                ]),
            ],
            'crm' => [
                'tickets' => $this->map($tickets, fn ($row) => [
                    'subject' => $row->subject,
                    'category' => ucwords(str_replace('_', ' ', (string) $row->category)),
                    'priority' => ucfirst((string) $row->priority),
                    'status' => $row->status,
                    'last_reply' => $row->last_reply_at,
                    'closed_at' => $row->closed_at,
                ]),
                'communications' => $this->map($communications, fn ($row) => [
                    'date' => $row->sent_at ?: $row->created_at,
                    'channel' => strtoupper((string) $row->channel),
                    'direction' => ucfirst((string) $row->direction),
                    'subject' => $row->subject,
                    'body' => $row->body,
                    'status' => $row->status,
                ]),
                'leads' => $this->map($leads, fn ($row) => [
                    'name' => $row->name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'source' => ucwords(str_replace('_', ' ', (string) $row->source)),
                    'status' => $row->status,
                    'notes' => $row->notes,
                ]),
            ],
            'governance' => [
                'votes' => $this->map($votes, fn ($row) => [
                    'resolution' => $row->resolution?->title,
                    'vote' => ucfirst((string) $row->vote),
                    'voted_at' => $row->voted_at,
                ]),
            ],
            'accounting' => [
                'ledger' => $this->map($ledgerLines, fn ($row) => [
                    'entry_no' => $row->journalEntry?->entry_no,
                    'date' => $row->journalEntry?->entry_date,
                    'account' => $row->account?->name,
                    'description' => $row->description ?: $row->journalEntry?->description,
                    'debit' => (int) $row->debit,
                    'credit' => (int) $row->credit,
                ]),
            ],
            'activity' => [
                'logins' => $this->map($logins, fn ($row) => [
                    'date' => $row->created_at,
                    'email' => $row->email,
                    'ip_address' => $row->ip_address,
                    'status' => $row->successful ? 'success' : 'failed',
                    'failure_reason' => $row->failure_reason,
                ]),
                'audit' => $this->map($audit, fn ($row) => [
                    'date' => $row->created_at,
                    'action' => ucwords(str_replace(['.', '_'], ' ', (string) $row->action)),
                    'user' => $row->user?->name,
                    'ip_address' => $row->ip_address,
                    'subject' => class_basename((string) $row->auditable_type),
                ]),
            ],
        ];

        if (! empty($can['reports'])) {
            $result['statement'] = app(ReportService::class)->memberStatement(
                $member,
                now()->subYears(2),
                now()
            );
        }

        return $result;
    }

    /**
     * Identity media is kept in the private EDMS, with a few legacy paths still
     * stored directly on the member row. Both are surfaced for authorised staff.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<string, mixed>
     */
    private function identityMedia(Member $member, Collection $documents): array
    {
        $metadata = $member->metadata ?? [];
        $signatureMeta = data_get($metadata, 'electronic_signature');
        $signatureMeta = is_array($signatureMeta) ? $signatureMeta : [];

        $signatureDocument = $this->signatureDocument($documents, $member);
        $biometrics = $documents->filter(
            fn (Document $document) => str_starts_with(strtolower((string) $document->type), 'biometric')
        );

        $biometricMetadata = collect($metadata)
            ->filter(fn ($value, $key) => str_contains(strtolower((string) $key), 'biometric'))
            ->map(fn ($value, $key) => [
                'key' => (string) $key,
                'value' => is_scalar($value)
                    ? mb_substr((string) $value, 0, 120)
                    : 'Stored ('.count((array) $value).' values)',
            ])
            ->values()
            ->all();

        return [
            'photo' => $this->mediaEntry(
                $member,
                'photo',
                'Passport photo',
                $documents->firstWhere('type', 'passport_photo'),
                $member->photo_path
            ),
            'video' => $this->mediaEntry(
                $member,
                'video',
                'Face verification video',
                $documents->firstWhere('type', 'face_video'),
                data_get($metadata, 'face_video_path')
            ),
            'signature' => $this->mediaEntry(
                $member,
                'signature',
                'Electronic signature',
                $signatureDocument,
                null,
                strtolower((string) data_get($signatureMeta, 'method', '')) === 'typed'
                    ? 'text/plain'
                    : 'image/png'
            ),
            'id_front' => $this->mediaEntry(
                $member,
                'id_front',
                'National ID copy (front)',
                $this->requirementDocument($documents, $member, 'national_id_copy', 'national_id')
            ),
            'id_back' => $this->mediaEntry(
                $member,
                'id_back',
                'National ID copy (back)',
                $this->requirementDocument($documents, $member, 'national_id_copy_back', 'national_id_back')
            ),
            'joining_documents' => $this->joiningDocuments($member, $documents),
            'signature_meta' => [
                'method' => isset($signatureMeta['method']) ? ucfirst((string) $signatureMeta['method']) : null,
                'name' => isset($signatureMeta['name']) ? (string) $signatureMeta['name'] : null,
                'consent' => (bool) data_get($signatureMeta, 'consent', false),
                'document_id' => (int) data_get($signatureMeta, 'document_id', 0),
            ],
            'biometrics' => [
                'required' => $member->biometricRequired(),
                'captured' => $biometrics->isNotEmpty(),
                'items' => $this->map($biometrics, fn (Document $document) => $this->mediaEntry(
                    $member,
                    'biometric',
                    ucwords(str_replace('_', ' ', (string) $document->type)),
                    $document
                )),
                'metadata' => $biometricMetadata,
            ],
        ];
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function signatureDocument(Collection $documents, Member $member): ?Document
    {
        $documentId = (int) data_get($member->metadata ?? [], 'electronic_signature.document_id', 0);

        if ($documentId) {
            $document = $documents->firstWhere('id', $documentId);

            if ($document instanceof Document && str_starts_with((string) $document->type, 'signature')) {
                return $document;
            }
        }

        $document = $documents->firstWhere('type', 'signature');

        return $document instanceof Document ? $document : null;
    }

    /**
     * Resolve a joining requirement from its recorded document id first, then
     * fall back to the document type for records uploaded outside the form.
     *
     * @param  Collection<int, Document>  $documents
     */
    private function requirementDocument(
        Collection $documents,
        Member $member,
        string $key,
        string $type
    ): ?Document {
        $documentId = (int) data_get(
            $member->metadata ?? [],
            'application_documents.submitted.'.$key.'.document_id',
            0
        );

        if ($documentId) {
            $document = $documents->firstWhere('id', $documentId);

            if ($document instanceof Document && strtolower((string) $document->type) === $type) {
                return $document;
            }
        }

        return $documents->first(
            fn (Document $document) => strtolower((string) $document->type) === $type
        ) ?: null;
    }

    /**
     * Every document submitted with the joining application, with a direct,
     * member-scoped preview link. Documents added later are included too.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<int, array<string, mixed>>
     */
    private function joiningDocuments(Member $member, Collection $documents): array
    {
        $submitted = data_get($member->metadata ?? [], 'application_documents.submitted', []);
        $submitted = is_array($submitted) ? $submitted : [];
        $items = [];
        $seen = [];

        foreach ($submitted as $key => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $documentId = (int) ($entry['document_id'] ?? 0);
            $document = $documentId ? $documents->firstWhere('id', $documentId) : null;

            if ($document instanceof Document) {
                $seen[$document->id] = true;
            }

            $items[] = $this->mediaEntry(
                $member,
                'document',
                (string) ($entry['label'] ?? ucwords(str_replace('_', ' ', (string) $key))),
                $document
            );
        }

        $joiningTypes = collect(JoinRequirements::DOCUMENTS)
            ->pluck('type')
            ->filter()
            ->unique()
            ->all();

        foreach ($documents as $document) {
            if (isset($seen[$document->id]) || ! in_array($document->type, $joiningTypes, true)) {
                continue;
            }

            $items[] = $this->mediaEntry(
                $member,
                'document',
                ucwords(str_replace('_', ' ', (string) $document->type)),
                $document
            );
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function flattenMetadata(array $metadata): array
    {
        $flat = [];

        $walk = function (mixed $value, string $prefix) use (&$walk, &$flat): void {
            if (is_array($value)) {
                foreach ($value as $key => $nested) {
                    $next = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                    if (is_array($nested)) {
                        $walk($nested, $next);
                    } else {
                        $flat[$next] = $this->metadataValue($nested);
                    }
                }

                return;
            }

            if ($prefix !== '') {
                $flat[$prefix] = $this->metadataValue($value);
            }
        };

        $walk($metadata, '');

        return $flat;
    }

    private function metadataValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value === null) {
            return '';
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? ''), 300);
    }

    /**
     * @param  mixed  $terms
     */
    private function termsSummary($terms): string
    {
        if (! is_array($terms)) {
            return '';
        }

        $parts = [];

        foreach ($terms as $key => $value) {
            if (is_array($value) || is_object($value) || is_bool($value)) {
                continue;
            }

            $parts[] = str_replace('_', ' ', (string) $key).': '.Str::limit((string) $value, 60);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaEntry(
        Member $member,
        string $key,
        string $label,
        ?Document $document,
        ?string $legacyPath = null,
        string $fallbackMime = 'application/octet-stream'
    ): array {
        if ($document instanceof Document) {
            return [
                'available' => true,
                'key' => $key,
                'label' => $label,
                'title' => $document->title,
                'status' => $document->status,
                'mime_type' => $document->mime_type,
                'size' => (int) $document->size,
                'uploaded_at' => $document->created_at,
                'path' => $document->path,
                'source' => 'EDMS',
                'document_id' => (int) $document->id,
                'preview_url' => route('members.lookup.media', [
                    'member' => $member->id,
                    'key' => $key,
                    'document' => $document->id,
                ]),
                'download_url' => route('documents.download', $document),
            ];
        }

        if (is_string($legacyPath) && trim($legacyPath) !== '') {
            return [
                'available' => true,
                'key' => $key,
                'label' => $label,
                'title' => $label,
                'status' => 'stored',
                'mime_type' => $this->mimeForPath($legacyPath),
                'size' => null,
                'uploaded_at' => null,
                'path' => $legacyPath,
                'source' => 'Member profile',
                'document_id' => null,
                'preview_url' => route('members.lookup.media', ['member' => $member->id, 'key' => $key]),
                'download_url' => null,
            ];
        }

        return [
            'available' => false,
            'key' => $key,
            'label' => $label,
            'title' => $label,
            'status' => null,
            'mime_type' => null,
            'size' => null,
            'uploaded_at' => null,
            'path' => null,
            'source' => null,
            'document_id' => null,
            'preview_url' => null,
            'download_url' => null,
        ];
    }

    /**
     * Resolve a preview request without ever escaping the member's tenant.
     */
    public function resolveMediaDocument(Member $member, string $key, ?int $documentId = null): ?Document
    {
        $query = Document::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id);

        if ($documentId) {
            return $query->whereKey($documentId)->first();
        }

        return match ($key) {
            'photo' => (clone $query)->where('type', 'passport_photo')->latest('id')->first(),
            'video' => (clone $query)->where('type', 'face_video')->latest('id')->first(),
            'signature' => $this->resolveSignatureDocument($query, $member),
            'id_front' => (clone $query)->where('type', 'national_id')->latest('id')->first(),
            'id_back' => (clone $query)->where('type', 'national_id_back')->latest('id')->first(),
            'biometric' => (clone $query)->where('type', 'like', 'biometric%')->latest('id')->first(),
            default => null,
        };
    }

    private function resolveSignatureDocument(Builder $query, Member $member): ?Document
    {
        $documentId = (int) data_get($member->metadata ?? [], 'electronic_signature.document_id', 0);

        if ($documentId) {
            $document = (clone $query)->whereKey($documentId)->first();

            if ($document instanceof Document && str_starts_with((string) $document->type, 'signature')) {
                return $document;
            }
        }

        return (clone $query)->where('type', 'signature')->latest('id')->first();
    }

    /**
     * @return array{path:string,label:string,mime_type:string}|null
     */
    public function legacyMediaPath(Member $member, string $key): ?array
    {
        $path = match ($key) {
            'photo' => $member->photo_path,
            'video' => data_get($member->metadata ?? [], 'face_video_path'),
            default => null,
        };

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $allowedPrefix = 'edms/groups/'.$member->group_id.'/members/';

        if (! str_starts_with(str_replace('\\', '/', $path), $allowedPrefix)) {
            return null;
        }

        $labels = [
            'photo' => 'Passport photo',
            'video' => 'Face verification video',
        ];

        return [
            'path' => $path,
            'label' => ($labels[$key] ?? 'Identity media').' · '.$member->member_no,
            'mime_type' => $this->mimeForPath($path),
        ];
    }

    private function mimeForPath(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'txt' => 'text/plain',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /**
     * Broad staff search across the member record and every linked record.
     *
     * Results are always tenant-scoped and the caller is responsible for the
     * final policy check before opening a 360° report.
     *
     * @param  array<string, bool>  $can
     * @return Collection<int, array{member: Member, matches: array<int, array{label:string,value:string}>}>
     */
    public function search(string $term, int $groupId, array $can, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return collect();
        }

        $lower = mb_strtolower($term);
        $needle = '%'.$lower.'%';
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $digitNeedle = $digits === '' ? null : '%'.$digits.'%';

        /** @var Collection<int, array{member: ?Member, matches: Collection<int, array{label:string,value:string}>}> $hits */
        $hits = collect();

        $add = function (int $memberId, string $label, mixed $value) use ($hits): void {
            $value = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '');

            if ($memberId < 1 || $value === '') {
                return;
            }

            if (! $hits->has($memberId)) {
                $hits->put($memberId, ['member' => null, 'matches' => collect()]);
            }

            $hit = $hits->get($memberId);
            $matches = $hit['matches'];

            if ($matches->contains(fn (array $match) => $match['label'] === $label && $match['value'] === $value)) {
                return;
            }

            if ($matches->count() < 4) {
                $matches->push(['label' => $label, 'value' => Str::limit($value, 100)]);
            }
        };

        $members = Member::withoutGlobalScopes()
            ->select('members.*')
            ->where('members.group_id', $groupId)
            ->where(function ($query) use ($needle, $digitNeedle, $lower) {
                foreach ([
                    'member_no', 'first_name', 'middle_name', 'last_name', 'national_id',
                    'phone', 'email', 'gender', 'address', 'occupation',
                    'employer', 'employer_address', 'workstation', 'membership_category',
                    'status', 'kyc_status', 'notes', 'rejection_reason', 'id_document_path',
                ] as $column) {
                    $query->orWhereRaw('LOWER(members.'.$column.') LIKE ?', [$needle]);
                }

                $query->orWhereRaw('LOWER('.$this->jsonText('members.date_of_birth').') LIKE ?', [$needle]);

                foreach (['member_no', 'national_id', 'phone'] as $column) {
                    if ($digitNeedle !== null) {
                        $query->orWhereRaw($this->normalizedExpression('members.'.$column).' LIKE ?', [$digitNeedle]);
                    }
                }

                $query->orWhereRaw('LOWER('.$this->jsonText('members.metadata').') LIKE ?', [$needle]);
                $query->orWhereHas('user', function ($query) use ($needle) {
                    $query->whereRaw('LOWER(users.name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(users.email) LIKE ?', [$needle]);
                });
            })
            ->orderByRaw('CASE WHEN LOWER(members.member_no) = ? THEN 0 ELSE 1 END', [$lower])
            ->orderBy('members.member_no')
            ->limit($limit * 3)
            ->get();

        $memberFields = [
            'member_no' => 'Member number',
            'national_id' => 'ID number',
            'phone' => 'Phone',
            'email' => 'Email',
            'workstation' => 'Workstation',
            'employer' => 'Employer',
            'employer_address' => 'Work address',
            'address' => 'Home address',
            'occupation' => 'Occupation',
            'membership_category' => 'Membership category',
            'status' => 'Status',
            'kyc_status' => 'KYC status',
            'notes' => 'Staff notes',
            'rejection_reason' => 'Rejection reason',
            'id_document_path' => 'ID document path',
        ];

        foreach ($members as $member) {
            $memberId = (int) $member->id;

            if ($this->valueMatches($member->full_name, $lower, $digits)) {
                $add($memberId, 'Name', $member->full_name);
            }

            foreach ($memberFields as $column => $label) {
                $value = $member->{$column};

                if ($this->valueMatches($value, $lower, $digits)) {
                    $add($memberId, $label, $value);
                }
            }

            $metadata = json_encode($member->metadata ?? [], JSON_UNESCAPED_UNICODE) ?: '';

            if ($this->valueMatches($metadata, $lower, $digits)) {
                $add($memberId, 'Application details', $metadata);
            }

            if ($member->user && $this->valueMatches($member->user->name, $lower, $digits)) {
                $add($memberId, 'Login name', $member->user->name);
            }
        }

        $sources = [
            ['savings_accounts', 'member_id', ['account_no' => 'Savings account', 'status' => 'Account status'], 'savings', ''],
            ['savings_transactions', 'member_id', ['reference' => 'Savings reference', 'description' => 'Savings description', 'type' => 'Savings type', 'idempotency_key' => 'Savings key'], 'savings', ''],
            ['loans', 'member_id', ['loan_no' => 'Loan number', 'purpose' => 'Loan purpose', 'purpose_description' => 'Loan purpose details', 'status' => 'Loan status'], 'loans', ''],
            ['loan_payments', 'member_id', ['reference' => 'Loan payment reference', 'method' => 'Loan payment method', 'status' => 'Loan payment status', 'notes' => 'Loan payment notes'], 'loans', ''],
            ['guarantors', 'guarantor_member_id', ['status' => 'Guarantee status', 'response_note' => 'Guarantee note'], 'loans', 'Guarantee'],
            ['wallet_transactions', 'member_id', ['reference' => 'Wallet reference', 'description' => 'Wallet description', 'provider_reference' => 'Wallet provider reference', 'type' => 'Wallet type', 'status' => 'Wallet status'], 'wallet', ''],
            ['investments', 'member_id', ['reference_no' => 'Investment reference', 'status' => 'Investment status', 'notes' => 'Investment notes'], 'investments', ''],
            ['investment_transactions', 'member_id', ['reference' => 'Investment transaction reference', 'description' => 'Investment description', 'type' => 'Investment type'], 'investments', ''],
            ['merry_go_round_participants', 'member_id', ['status' => 'MGR status'], 'mgr', 'MGR'],
            ['merry_go_round_contributions', 'member_id', ['reference' => 'MGR contribution reference', 'status' => 'MGR contribution status'], 'mgr', 'MGR'],
            ['merry_go_round_payouts', 'member_id', ['reference' => 'MGR payout reference', 'status' => 'MGR payout status'], 'mgr', 'MGR'],
            ['payment_intents', 'member_id', ['provider_reference' => 'Payment reference', 'phone' => 'Payment phone', 'purpose' => 'Payment purpose', 'status' => 'Payment status', 'failure_reason' => 'Payment failure'], 'payments', ''],
            ['bills', 'member_id', ['bill_no' => 'Bill number', 'payee_name' => 'Bill payee', 'payee_account' => 'Bill payee account', 'phone' => 'Bill phone', 'description' => 'Bill description', 'status' => 'Bill status'], 'payments', ''],
            ['bill_payments', 'member_id', ['reference' => 'Bill payment reference', 'method' => 'Bill payment method', 'borne_by' => 'Bill charges borne by'], 'payments', ''],
            ['payouts', 'member_id', ['provider_reference' => 'Payout reference', 'provider' => 'Payout provider', 'status' => 'Payout status', 'failure_reason' => 'Payout failure'], 'payments', ''],
            ['teller_entries', 'member_id', ['receipt_no' => 'Receipt number', 'reference' => 'Teller reference', 'category' => 'Teller category', 'method' => 'Teller method', 'narration' => 'Teller narration'], 'teller', ''],
            ['fines', 'member_id', ['type' => 'Fine type', 'reason' => 'Fine reason', 'status' => 'Fine status'], 'meetings', ''],
            ['meeting_attendances', 'member_id', ['status' => 'Attendance status', 'notes' => 'Attendance notes'], 'meetings', ''],
            ['meeting_collections', 'member_id', ['type' => 'Meeting collection type', 'reference' => 'Meeting collection reference'], 'meetings', ''],
            ['welfare_contributions', 'member_id', ['reference' => 'Welfare reference'], 'welfare', ''],
            ['welfare_claims', 'member_id', ['type' => 'Welfare claim type', 'reason' => 'Welfare claim reason', 'status' => 'Welfare claim status', 'notes' => 'Welfare claim notes'], 'welfare', ''],
            ['documents', 'member_id', ['title' => 'Document title', 'type' => 'Document type', 'status' => 'Document status', 'notes' => 'Document notes', 'path' => 'Document path'], 'documents', ''],
            ['next_of_kin', 'member_id', ['name' => 'Next of kin', 'relationship' => 'Next of kin relationship', 'phone' => 'Next of kin phone', 'email' => 'Next of kin email', 'id_number' => 'Next of kin ID number', 'address' => 'Next of kin address'], 'overview', ''],
            ['beneficiaries', 'member_id', ['name' => 'Beneficiary', 'relationship' => 'Beneficiary relationship', 'phone' => 'Beneficiary phone', 'id_number' => 'Beneficiary ID number'], 'overview', ''],
            ['member_invitations', 'inviter_member_id', ['email' => 'Sent invitation email', 'phone' => 'Sent invitation phone', 'note' => 'Invitation note', 'status' => 'Invitation status'], 'overview', ''],
            ['member_invitations', 'accepted_member_id', ['email' => 'Accepted invitation email', 'phone' => 'Accepted invitation phone', 'note' => 'Invitation note', 'status' => 'Invitation status'], 'overview', ''],
            ['notification_logs', 'member_id', ['recipient' => 'Notification recipient', 'template_key' => 'Notification template', 'status' => 'Notification status', 'provider_reference' => 'Notification reference', 'error' => 'Notification error'], 'notifications', ''],
            ['support_tickets', 'member_id', ['subject' => 'Support ticket', 'category' => 'Ticket category', 'priority' => 'Ticket priority', 'status' => 'Ticket status'], 'crm', ''],
            ['communications', 'member_id', ['subject' => 'Communication subject', 'body' => 'Communication body', 'channel' => 'Communication channel', 'status' => 'Communication status'], 'crm', ''],
            ['leads', 'converted_member_id', ['name' => 'Converted lead', 'phone' => 'Lead phone', 'email' => 'Lead email', 'status' => 'Lead status', 'notes' => 'Lead notes'], 'crm', ''],
            ['votes', 'member_id', ['vote' => 'Governance vote'], 'governance', ''],
            ['ledger_lines', 'member_id', ['description' => 'Ledger description'], 'accounting', ''],
        ];

        foreach ($sources as [$table, $memberColumn, $columns, $permission, $prefix]) {
            if (! ($can[$permission] ?? false)) {
                continue;
            }

            $this->searchRelatedTable($table, $memberColumn, $columns, $groupId, $lower, $digits, $add, $prefix);
        }

        if ($can['loans'] ?? false) {
            $loanMembers = Loan::withoutGlobalScopes()
                ->where('group_id', $groupId)
                ->pluck('member_id', 'id');

            $this->searchLoanRelatedTable(
                'loan_approvals',
                ['action' => 'Loan approval action', 'role_key' => 'Loan approval role', 'comments' => 'Loan approval note'],
                $groupId,
                $loanMembers,
                $lower,
                $digits,
                $add
            );
            $this->searchLoanRelatedTable(
                'loan_restructures',
                ['reason' => 'Loan restructure reason', 'status' => 'Loan restructure status'],
                $groupId,
                $loanMembers,
                $lower,
                $digits,
                $add
            );
            $this->searchLoanRelatedTable(
                'loan_penalty_charges',
                ['status' => 'Loan penalty status'],
                $groupId,
                $loanMembers,
                $lower,
                $digits,
                $add
            );
        }

        if ($can['activity'] ?? false) {
            $this->searchActivity($groupId, $lower, $digits, $add);
        }

        $memberIds = $hits->keys()->all();

        if ($memberIds === []) {
            return collect();
        }

        $membersById = Member::withoutGlobalScopes()
            ->where('group_id', $groupId)
            ->whereIn('id', $memberIds)
            ->with(['user', 'role'])
            ->get()
            ->keyBy('id');

        return $hits
            ->filter(fn (array $hit, int $memberId) => $membersById->has($memberId))
            ->map(function (array $hit, int $memberId) use ($membersById) {
                $hit['member'] = $membersById->get($memberId);
                $hit['matches'] = $hit['matches']->values()->all();

                return $hit;
            })
            ->sortBy(fn (array $hit) => $hit['member']->member_no)
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function searchRelatedTable(
        string $table,
        string $memberColumn,
        array $columns,
        int $groupId,
        string $lower,
        string $digits,
        callable $add,
        string $prefix = ''
    ): void {
        $needle = '%'.$lower.'%';
        $digitNeedle = $digits === '' ? null : '%'.$digits.'%';
        $codeColumns = [
            'national_id', 'id_number', 'phone', 'reference', 'provider_reference',
            'account_no', 'bill_no', 'loan_no', 'receipt_no', 'reference_no',
            'ip_address', 'payee_account',
        ];

        $rows = DB::table($table)
            ->where($table.'.group_id', $groupId)
            ->whereNotNull($table.'.'.$memberColumn)
            ->where(function ($query) use ($table, $columns, $needle, $digitNeedle, $codeColumns) {
                foreach (array_keys($columns) as $column) {
                    $qualified = $table.'.'.$column;
                    $query->orWhereRaw('LOWER('.$qualified.') LIKE ?', [$needle]);

                    if ($digitNeedle !== null && in_array($column, $codeColumns, true)) {
                        $query->orWhereRaw($this->normalizedExpression($qualified).' LIKE ?', [$digitNeedle]);
                    }
                }
            })
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            foreach ($columns as $column => $label) {
                if (! $this->valueMatches($row->{$column} ?? null, $lower, $digits)) {
                    continue;
                }

                $add(
                    (int) $row->{$memberColumn},
                    $prefix === '' ? $label : $prefix.' · '.$label,
                    (string) $row->{$column}
                );

                break;
            }
        }
    }

    /**
     * Search records that hang off a loan rather than the member directly.
     *
     * @param  array<string, string>  $columns
     * @param  Collection<int|string, int>  $loanMembers  loan id => member id
     */
    private function searchLoanRelatedTable(
        string $table,
        array $columns,
        int $groupId,
        Collection $loanMembers,
        string $lower,
        string $digits,
        callable $add
    ): void {
        $loanIds = $loanMembers->keys()->all();

        if ($loanIds === []) {
            return;
        }

        $needle = '%'.$lower.'%';
        $digitNeedle = $digits === '' ? null : '%'.$digits.'%';

        $rows = DB::table($table)
            ->where($table.'.group_id', $groupId)
            ->whereIn($table.'.loan_id', $loanIds)
            ->where(function ($query) use ($table, $columns, $needle, $digitNeedle) {
                foreach (array_keys($columns) as $column) {
                    $query->orWhereRaw('LOWER('.$table.'.'.$column.') LIKE ?', [$needle]);

                    if ($digitNeedle !== null) {
                        $query->orWhereRaw($this->normalizedExpression($table.'.'.$column).' LIKE ?', [$digitNeedle]);
                    }
                }
            })
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            $memberId = (int) $loanMembers->get((int) $row->loan_id, 0);

            if ($memberId < 1) {
                continue;
            }

            foreach ($columns as $column => $label) {
                if (! $this->valueMatches($row->{$column} ?? null, $lower, $digits)) {
                    continue;
                }

                $add($memberId, $label, (string) $row->{$column});

                break;
            }
        }
    }

    private function searchActivity(int $groupId, string $lower, string $digits, callable $add): void
    {
        $membersByUser = Member::withoutGlobalScopes()
            ->where('group_id', $groupId)
            ->whereNotNull('user_id')
            ->pluck('id', 'user_id');

        $auditColumns = [
            'action' => 'Audit action',
            'old_values' => 'Previous values',
            'new_values' => 'Changed values',
            'ip_address' => 'Audit IP address',
            'user_agent' => 'Audit user agent',
        ];

        $auditLogs = DB::table('audit_logs')
            ->where('group_id', $groupId)
            ->where(function ($query) use ($lower, $auditColumns) {
                foreach (array_keys($auditColumns) as $column) {
                    $expression = in_array($column, ['old_values', 'new_values'], true)
                        ? $this->jsonText('audit_logs.'.$column)
                        : 'audit_logs.'.$column;

                    $query->orWhereRaw('LOWER('.$expression.') LIKE ?', ['%'.$lower.'%']);
                }
            })
            ->limit(50)
            ->get();

        foreach ($auditLogs as $log) {
            $memberIds = [];

            if (
                $log->auditable_id
                && ($log->auditable_type === Member::class || class_basename((string) $log->auditable_type) === 'Member')
            ) {
                $memberIds[] = (int) $log->auditable_id;
            }

            if ($log->user_id && $membersByUser->has((int) $log->user_id)) {
                $memberIds[] = (int) $membersByUser->get((int) $log->user_id);
            }

            foreach (array_unique($memberIds) as $memberId) {
                foreach ($auditColumns as $column => $label) {
                    if ($this->valueMatches($log->{$column} ?? null, $lower, $digits)) {
                        $add($memberId, $label, (string) $log->{$column});

                        break;
                    }
                }
            }
        }

        $userIds = $membersByUser->keys()->all();

        if ($userIds === []) {
            return;
        }

        $loginColumns = [
            'email' => 'Login email',
            'ip_address' => 'Login IP address',
            'user_agent' => 'Login user agent',
            'failure_reason' => 'Login failure reason',
        ];

        $logins = DB::table('login_activities')
            ->whereIn('user_id', $userIds)
            ->where(function ($query) use ($lower, $loginColumns) {
                foreach (array_keys($loginColumns) as $column) {
                    $query->orWhereRaw('LOWER(login_activities.'.$column.') LIKE ?', ['%'.$lower.'%']);
                }
            })
            ->limit(50)
            ->get();

        foreach ($logins as $login) {
            if (! $membersByUser->has((int) $login->user_id)) {
                continue;
            }

            foreach ($loginColumns as $column => $label) {
                if ($this->valueMatches($login->{$column} ?? null, $lower, $digits)) {
                    $add((int) $membersByUser->get((int) $login->user_id), $label, (string) $login->{$column});

                    break;
                }
            }
        }
    }

    private function valueMatches(mixed $value, string $lower, string $digits): bool
    {
        if ($value === null || is_bool($value)) {
            return false;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? ''));

        if ($text === '') {
            return false;
        }

        if (str_contains($text, $lower)) {
            return true;
        }

        if ($digits !== '') {
            $valueDigits = preg_replace('/\D+/', '', $text) ?? '';

            return $valueDigits !== '' && str_contains($valueDigits, $digits);
        }

        return false;
    }

    private function normalizedExpression(string $column): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''))";
    }

    private function jsonText(string $column): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? 'CAST('.$column.' AS CHAR)'
            : 'CAST('.$column.' AS TEXT)';
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $records
     * @return array<int, array<string, mixed>>
     */
    private function map(Collection $records, callable $callback): array
    {
        return $records->map($callback)->values()->all();
    }
}
