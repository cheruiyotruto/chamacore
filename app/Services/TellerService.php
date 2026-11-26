<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\SavingsAccount;
use App\Models\TellerEntry;
use App\Models\TellerSession;
use App\Models\User;
use App\Support\MgrSavingsSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TellerService
{
    public function __construct(
        private readonly SavingsService $savings,
        private readonly LoanServicingService $loans,
        private readonly MerryGoRoundService $mgr,
        private readonly WalletService $wallets,
        private readonly InvestmentService $investments,
        private readonly ChargeService $charges,
    ) {}

    public function openSession(Group $group, User $actor, int $openingFloat, ?string $notes = null): TellerSession
    {
        if ($openingFloat < 0) {
            throw new BusinessRuleException('The opening float cannot be negative.');
        }

        $alreadyOpen = TellerSession::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('user_id', $actor->id)
            ->where('status', TellerSession::STATUS_OPEN)
            ->exists();

        if ($alreadyOpen) {
            throw new BusinessRuleException('You already have an open till. Close it before opening another.');
        }

        return TellerSession::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'user_id' => $actor->id,
            'status' => TellerSession::STATUS_OPEN,
            'opening_float' => $openingFloat,
            'expected_cash' => $openingFloat,
            'opened_at' => now(),
            'notes' => $notes,
        ]);
    }

    public function closeSession(TellerSession $session, int $countedCash, ?string $notes, User $actor): TellerSession
    {
        if (! $session->isOpen()) {
            throw new BusinessRuleException('This till is already closed.');
        }

        if ($countedCash < 0) {
            throw new BusinessRuleException('The counted cash cannot be negative.');
        }

        $expected = $session->expectedCash();

        $session->update([
            'status' => TellerSession::STATUS_CLOSED,
            'expected_cash' => $expected,
            'counted_cash' => $countedCash,
            'variance' => $countedCash - $expected,
            'closed_at' => now(),
            'closing_notes' => $notes,
        ]);

        AuditService::log('teller.session.closed', [
            'teller_session_id' => $session->id,
            'expected_cash' => $expected,
            'counted_cash' => $countedCash,
            'variance' => $countedCash - $expected,
            'closed_by' => $actor->id,
        ], $session->group);

        return $session->refresh();
    }

    /**
     * Capture a teller transaction. Cheques are held as pending entries and
     * only reach the ledger when {@see self::clearCheque()} is called.
     *
     * @param  array<string, mixed>  $data
     */
    public function post(TellerSession $session, array $data, User $actor): TellerEntry
    {
        if (! $session->isOpen()) {
            throw new BusinessRuleException('Open a till before posting transactions.');
        }

        $category = (string) ($data['category'] ?? '');

        if (! array_key_exists($category, TellerEntry::CATEGORIES)) {
            throw new BusinessRuleException('Select a valid teller transaction type.');
        }

        $amount = (int) ($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new BusinessRuleException('The amount must be greater than zero.');
        }

        $method = (string) ($data['method'] ?? 'cash');

        if (! in_array($method, ['cash', 'bank', 'mpesa', TellerEntry::METHOD_CHEQUE], true)) {
            throw new BusinessRuleException('Select a valid payment method.');
        }

        if ($method === TellerEntry::METHOD_CHEQUE && TellerEntry::directionFor($category) === TellerEntry::DIRECTION_OUT) {
            throw new BusinessRuleException('Cheques can only be accepted for deposits and repayments.');
        }

        $group = Group::withoutGlobalScopes()->findOrFail($session->group_id);
        $member = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->find($data['member_id'] ?? null);

        if (! $member) {
            throw new BusinessRuleException('Select a member for this transaction.');
        }

        if ($member->status !== 'active') {
            throw new BusinessRuleException('Only active members can transact at the teller desk.');
        }

        $reference = trim((string) ($data['reference'] ?? ''));
        $narration = trim((string) ($data['narration'] ?? '')) ?: null;
        $idempotencyKey = (string) ($data['idempotency_key'] ?? 'teller-'.Str::uuid());
        $isCheque = $method === TellerEntry::METHOD_CHEQUE;
        $chequeDetails = $isCheque ? $this->chequeDetails($data) : null;

        if ($isCheque && $reference === '') {
            $reference = (string) ($chequeDetails['cheque_number'] ?? '');
        }

        $data['idempotency_key'] = $idempotencyKey;
        $data['reference'] = $reference;
        $data['member_id'] = $member->id;
        $data['amount'] = $amount;
        $data['category'] = $category;
        $data['method'] = $method;
        $data['narration'] = $narration;

        $counter = $method === 'cash' ? LedgerService::CASH : LedgerService::BANK;
        $settlementMethod = $method === 'cash' ? 'cash' : 'bank';
        $domainMethod = $isCheque ? 'bank' : $method;

        return DB::transaction(function () use (
            $session, $data, $actor, $category, $amount, $method, $domainMethod,
            $settlementMethod, $counter, $group, $member, $reference, $narration,
            $idempotencyKey, $isCheque, $chequeDetails
        ) {
            $result = null;
            $entryAmount = $amount;
            $chargeResult = ['total' => 0, 'waived' => 0, 'lines' => []];

            if (! $isCheque) {
                $result = $this->postThroughDomain(
                    $group, $member, $data, $amount, $category, $domainMethod,
                    $settlementMethod, $counter, $reference, $narration,
                    $idempotencyKey, $actor
                );
                $entryAmount = $this->entryAmountFor($result, $amount, $group);
                $chargeResult = $this->calculateCharges($group, $member, $category, $entryAmount, $data);
            }

            $entry = TellerEntry::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'teller_session_id' => $session->id,
                'member_id' => $member->id,
                'receipt_no' => 'TMP-'.Str::uuid(),
                'category' => $category,
                'direction' => TellerEntry::directionFor($category),
                'amount' => $entryAmount,
                'charge_amount' => $chargeResult['total'],
                'waived_charge_amount' => $chargeResult['waived'],
                'charge_breakdown' => $chargeResult['lines'] ?: null,
                'method' => $method,
                'status' => $isCheque ? TellerEntry::STATUS_PENDING : TellerEntry::STATUS_POSTED,
                'reference' => $reference ?: null,
                'narration' => $narration,
                'cheque_details' => $chequeDetails,
                'payload' => $isCheque ? $this->chequePayload($data, $chequeDetails) : null,
                'source_type' => $result?->getMorphClass(),
                'source_id' => $result?->getKey(),
                'journal_entry_id' => $result ? $this->journalIdFor($result) : null,
                'created_by' => $actor->id,
            ]);

            $entry->update([
                'receipt_no' => 'RCP-'.now()->format('Ymd').'-'.str_pad((string) $entry->id, 5, '0', STR_PAD_LEFT),
            ]);

            if (! $isCheque) {
                $this->postCharges($group, $member, $entry, $counter, $chargeResult, $reference);
            }

            AuditService::log($isCheque ? 'teller.entry.cheque_captured' : 'teller.entry.posted', [
                'teller_entry_id' => $entry->id,
                'receipt_no' => $entry->receipt_no,
                'category' => $category,
                'direction' => $entry->direction,
                'amount' => $entryAmount,
                'charge_amount' => $chargeResult['total'],
                'waived_charge_amount' => $chargeResult['waived'],
                'method' => $method,
                'status' => $entry->status,
                'member_id' => $member->id,
            ], $group);

            return $entry->refresh();
        });
    }

    /**
     * Clear or bounce a captured cheque. Clearing replays the original teller
     * instructions through the domain services using a bank counter account.
     *
     * @param  string  $outcome  one of the TellerEntry::STATUS_* cheque outcomes
     */
    public function clearCheque(TellerEntry $entry, string $outcome, User $actor, ?string $notes = null): TellerEntry
    {
        if (! in_array($outcome, [TellerEntry::STATUS_CLEARED, TellerEntry::STATUS_BOUNCED], true)) {
            throw new BusinessRuleException('Select a valid cheque outcome.');
        }

        return DB::transaction(function () use ($entry, $outcome, $actor, $notes) {
            $entry = TellerEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($entry->id);

            if (! $entry->canResolveCheque()) {
                throw new BusinessRuleException('Only a pending cheque can be cleared or bounced.');
            }

            $group = Group::withoutGlobalScopes()->findOrFail($entry->group_id);
            $chequeDetails = (array) ($entry->cheque_details ?? []);

            if ($outcome === TellerEntry::STATUS_BOUNCED) {
                $entry->update([
                    'status' => TellerEntry::STATUS_BOUNCED,
                    'bounced_at' => now(),
                    'bounced_by' => $actor->id,
                    'clearance_notes' => $notes,
                ]);

                AuditService::log('teller.cheque.bounced', [
                    'teller_entry_id' => $entry->id,
                    'receipt_no' => $entry->receipt_no,
                    'cheque_number' => $chequeDetails['cheque_number'] ?? null,
                    'amount' => $entry->amount,
                    'bounced_by' => $actor->id,
                    'notes' => $notes,
                ], $group);

                return $entry->refresh();
            }

            $payload = (array) ($entry->payload ?? []);

            if ($payload === []) {
                throw new BusinessRuleException('This cheque is missing its original deposit details.');
            }

            $member = Member::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->find($payload['member_id'] ?? $entry->member_id);

            if (! $member) {
                throw new BusinessRuleException('The member for this cheque could not be found.');
            }

            $category = (string) ($payload['category'] ?? $entry->category);
            $amount = (int) ($payload['amount'] ?? $entry->amount);
            $reference = trim((string) ($payload['reference'] ?? $entry->reference ?? ''))
                ?: (string) ($chequeDetails['cheque_number'] ?? '');
            $narration = $payload['narration'] ?? $entry->narration;
            $idempotencyKey = (string) ($payload['idempotency_key'] ?? 'teller-cheque-'.$entry->id);
            $counter = LedgerService::BANK;
            $settlementMethod = 'bank';
            $data = $payload;
            $data['reference'] = $reference;

            $result = $this->postThroughDomain(
                $group, $member, $data, $amount, $category, 'bank',
                $settlementMethod, $counter, $reference, $narration,
                $idempotencyKey, $actor
            );

            $entryAmount = $this->entryAmountFor($result, $amount, $group);
            $chargeResult = $this->calculateCharges($group, $member, $category, $entryAmount, $data);

            $entry->update([
                'status' => TellerEntry::STATUS_CLEARED,
                'amount' => $entryAmount,
                'charge_amount' => $chargeResult['total'],
                'waived_charge_amount' => $chargeResult['waived'],
                'charge_breakdown' => $chargeResult['lines'] ?: null,
                'cleared_at' => now(),
                'cleared_by' => $actor->id,
                'clearance_notes' => $notes,
                'source_type' => $result->getMorphClass(),
                'source_id' => $result->getKey(),
                'journal_entry_id' => $this->journalIdFor($result),
            ]);

            $this->postCharges($group, $member, $entry, $counter, $chargeResult, $reference);

            AuditService::log('teller.cheque.cleared', [
                'teller_entry_id' => $entry->id,
                'receipt_no' => $entry->receipt_no,
                'cheque_number' => $chequeDetails['cheque_number'] ?? null,
                'category' => $category,
                'amount' => $entryAmount,
                'journal_entry_id' => $entry->journal_entry_id,
                'charge_amount' => $chargeResult['total'],
                'cleared_by' => $actor->id,
                'notes' => $notes,
            ], $group);

            return $entry->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function chequeDetails(array $data): array
    {
        $details = [
            'cheque_number' => trim((string) ($data['cheque_number'] ?? '')),
            'bank_name' => trim((string) ($data['bank_name'] ?? '')),
            'branch' => trim((string) ($data['branch'] ?? '')),
            'drawer_name' => trim((string) ($data['drawer_name'] ?? '')),
            'cheque_date' => trim((string) ($data['cheque_date'] ?? '')),
            'payee_name' => trim((string) ($data['payee_name'] ?? '')),
        ];

        if ($details['cheque_number'] === '' || $details['bank_name'] === '' || $details['cheque_date'] === '') {
            throw new BusinessRuleException('Capture the cheque number, bank and cheque date.');
        }

        return array_filter($details, fn (string $value) => $value !== '');
    }

    /**
     * Keep the original instructions so clearance can replay them against the
     * same domain services instead of writing directly to the ledger.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $chequeDetails
     * @return array<string, mixed>
     */
    private function chequePayload(array $data, array $chequeDetails): array
    {
        return [
            'member_id' => $data['member_id'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'method' => TellerEntry::METHOD_CHEQUE,
            'reference' => $data['reference'] ?? null,
            'narration' => $data['narration'] ?? null,
            'idempotency_key' => $data['idempotency_key'],
            'waive_charges' => (bool) ($data['waive_charges'] ?? false),
            'savings_account_id' => $data['savings_account_id'] ?? null,
            'loan_id' => $data['loan_id'] ?? null,
            'contribution_id' => $data['contribution_id'] ?? null,
            'investment_product_id' => $data['investment_product_id'] ?? null,
            'investment_id' => $data['investment_id'] ?? null,
            'cheque_details' => $chequeDetails,
        ];
    }

    /**
     * Post through the services the cash desk already uses. Cheques replay
     * this path with a bank counter account when they clear.
     *
     * @param  array<string, mixed>  $data
     */
    private function postThroughDomain(
        Group $group,
        Member $member,
        array $data,
        int $amount,
        string $category,
        string $domainMethod,
        string $settlementMethod,
        string $counter,
        string $reference,
        ?string $narration,
        string $idempotencyKey,
        User $actor
    ): object {
        return match ($category) {
            'savings_deposit' => $this->savings->deposit(
                $member,
                $this->savingsAccount($member, $data['savings_account_id'] ?? null),
                $amount,
                'deposit',
                $counter,
                $reference,
                $idempotencyKey,
                $narration ?: 'Teller savings deposit',
            ),
            'savings_withdrawal' => $this->savings->withdraw(
                $member,
                $this->savingsAccount($member, $data['savings_account_id'] ?? null),
                $amount,
                $counter,
                $reference,
                $idempotencyKey,
                $narration ?: 'Teller savings withdrawal',
            ),
            'loan_repayment' => $this->loans->recordPayment(
                $this->loan($member, $data['loan_id'] ?? null),
                $amount,
                $settlementMethod,
                $reference,
                $idempotencyKey,
                $actor,
                null,
                $narration,
            ),
            'mgr_contribution' => $this->mgr->recordContribution(
                $this->contribution($member, $data['contribution_id'] ?? null),
                $actor,
                $domainMethod,
                $reference ?: null,
            ),
            'investment_place' => $this->investments->open(
                $member,
                $this->investmentProduct($group, $data['investment_product_id'] ?? null),
                $amount,
                $actor,
                null,
                $settlementMethod,
                $reference ?: null,
                $narration,
            ),
            'investment_payout' => $this->investments->withdraw(
                $this->investment($member, $data['investment_id'] ?? null),
                $settlementMethod,
                $actor,
            ),
            'wallet_deposit' => $this->wallets->credit(
                $member,
                $amount,
                'teller_deposit',
                $counter,
                $reference ?: 'WALLET-TELLER-'.Str::upper(Str::random(6)),
                $idempotencyKey,
                $narration ?: 'Wallet top-up at teller',
            ),
            'wallet_withdrawal' => $this->wallets->debit(
                $member,
                $amount,
                'teller_withdrawal',
                $counter,
                $reference ?: 'WALLET-TELLER-'.Str::upper(Str::random(6)),
                $idempotencyKey,
                $narration ?: 'Wallet withdrawal at teller',
            ),
        };
    }

    private function entryAmountFor(object $result, int $fallback, Group $group): int
    {
        if ($result instanceof Investment) {
            return (int) $result->principal;
        }

        if ($result instanceof MerryGoRoundContribution) {
            // The receipt shows the cash taken at the till, including the
            // automatic savings portion configured for the group.
            $allocation = MgrSavingsSettings::for($group)->allocate((int) $result->amount);

            return $allocation['mgr'] + $allocation['savings'];
        }

        return (int) ($result->amount ?? $fallback);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{total:int, waived:int, lines:array<int, array<string, mixed>>}
     */
    private function calculateCharges(
        Group $group,
        Member $member,
        string $category,
        int $entryAmount,
        array $data
    ): array {
        return $this->charges->calculate(
            $this->charges->matching($group, $category, $member),
            $entryAmount,
            (bool) ($data['waive_charges'] ?? false),
            TellerEntry::directionFor($category),
        );
    }

    /**
     * @param  array{total:int, waived:int, lines:array<int, array<string, mixed>>}  $chargeResult
     */
    private function postCharges(
        Group $group,
        Member $member,
        TellerEntry $entry,
        string $counter,
        array $chargeResult,
        string $reference
    ): void {
        $chargeJournal = $this->charges->post(
            $group,
            $member,
            $entry,
            $counter,
            $chargeResult['total'],
            $chargeResult['lines'],
            $reference,
        );

        if ($chargeJournal) {
            $entry->update(['charge_journal_entry_id' => $chargeJournal->id]);
        }
    }

    private function savingsAccount(Member $member, mixed $accountId): SavingsAccount
    {
        $account = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->find($accountId);

        if (! $account) {
            throw new BusinessRuleException('Select a savings account for this member.');
        }

        return $account;
    }

    private function loan(Member $member, mixed $loanId): Loan
    {
        $loan = Loan::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->find($loanId);

        if (! $loan) {
            throw new BusinessRuleException('Select a loan belonging to this member.');
        }

        if (! $loan->isActive()) {
            throw new BusinessRuleException('This loan is not active.');
        }

        return $loan;
    }

    private function contribution(Member $member, mixed $contributionId): MerryGoRoundContribution
    {
        $contribution = MerryGoRoundContribution::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->find($contributionId);

        if (! $contribution) {
            throw new BusinessRuleException('Select a merry-go-round contribution for this member.');
        }

        return $contribution;
    }

    private function investmentProduct(Group $group, mixed $productId): InvestmentProduct
    {
        $product = InvestmentProduct::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('is_active', true)
            ->find($productId);

        if (! $product) {
            throw new BusinessRuleException('Select an open investment product.');
        }

        return $product;
    }

    private function investment(Member $member, mixed $investmentId): Investment
    {
        $investment = Investment::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->find($investmentId);

        if (! $investment) {
            throw new BusinessRuleException('Select an investment belonging to this member.');
        }

        if (! $investment->isOpen()) {
            throw new BusinessRuleException('This investment is already settled.');
        }

        return $investment;
    }

    private function journalIdFor(object $result): ?int
    {
        $direct = $result->journal_entry_id ?? null;

        if ($direct) {
            return (int) $direct;
        }

        return JournalEntry::withoutGlobalScopes()
            ->where('source_type', $result->getMorphClass())
            ->where('source_id', $result->getKey())
            ->latest('id')
            ->value('id');
    }
}
