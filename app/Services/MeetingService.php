<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Fine;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingCollection;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Models\WelfareFund;
use Illuminate\Support\Facades\DB;

class MeetingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SavingsService $savings,
        private readonly WelfareService $welfare,
        private readonly NotificationService $notifications,
    ) {
    }

    public function create(Group $group, array $data, User $actor): Meeting
    {
        return Meeting::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'title' => $data['title'],
            'type' => $data['type'] ?? 'regular',
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'] ?? null,
            'agenda' => $data['agenda'] ?? null,
            'status' => 'scheduled',
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<int, array{member_id:int, status:string, fine_amount?:int, notes?:string}>  $records
     */
    public function saveAttendance(Meeting $meeting, array $records, User $actor): int
    {
        return DB::transaction(function () use ($meeting, $records, $actor) {
            $saved = 0;

            foreach ($records as $record) {
                $member = Member::withoutGlobalScopes()
                    ->where('group_id', $meeting->group_id)
                    ->findOrFail($record['member_id']);

                $status = in_array($record['status'], ['present', 'absent', 'late', 'excused'], true)
                    ? $record['status']
                    : 'absent';

                $fineAmount = (int) ($record['fine_amount'] ?? 0);

                if ($status === 'present' || $status === 'excused') {
                    $fineAmount = 0;
                }

                MeetingAttendance::withoutGlobalScopes()->updateOrCreate(
                    ['meeting_id' => $meeting->id, 'member_id' => $member->id],
                    [
                        'group_id' => $meeting->group_id,
                        'status' => $status,
                        'fine_amount' => $fineAmount,
                        'notes' => $record['notes'] ?? null,
                    ]
                );

                if ($fineAmount > 0) {
                    $fine = Fine::withoutGlobalScopes()->firstOrCreate(
                        [
                            'group_id' => $meeting->group_id,
                            'member_id' => $member->id,
                            'meeting_id' => $meeting->id,
                            'type' => $status === 'late' ? 'late_attendance' : 'absenteeism',
                        ],
                        [
                            'amount' => $fineAmount,
                            'amount_paid' => 0,
                            'reason' => $status === 'late' ? 'Late to meeting' : 'Absent from meeting',
                            'status' => 'pending',
                            'due_date' => now()->toDateString(),
                        ]
                    );

                    if ($fine->amount !== $fineAmount && $fine->amount_paid === 0) {
                        $fine->update(['amount' => $fineAmount]);
                    }

                    $this->notifications->send($member, 'fine_issued', [
                        'amount' => \App\Support\Money::format($fine->amount),
                        'reason' => $fine->reason,
                    ]);
                }

                $saved++;
            }

            AuditService::log('meeting.attendance.saved', [
                'meeting_id' => $meeting->id,
                'records' => $saved,
            ], Group::withoutGlobalScopes()->find($meeting->group_id));

            return $saved;
        });
    }

    /**
     * Record a cash/bank collection at a meeting.
     *
     * @param  array{member_id:int, type:string, amount:int, savings_product_id?:int, fine_id?:int, welfare_fund_id?:int, method?:string, reference?:string}  $data
     */
    public function recordCollection(Meeting $meeting, array $data, User $actor): MeetingCollection
    {
        if (! $meeting->group_id) {
            throw new BusinessRuleException('Invalid meeting.');
        }

        $group = Group::withoutGlobalScopes()->findOrFail($meeting->group_id);
        $member = Member::withoutGlobalScopes()
            ->where('group_id', $meeting->group_id)
            ->findOrFail($data['member_id']);

        $amount = (int) $data['amount'];

        if ($amount <= 0) {
            throw new BusinessRuleException('Collection amount must be greater than zero.');
        }

        return DB::transaction(function () use ($meeting, $group, $member, $amount, $data, $actor) {
            $method = $data['method'] ?? 'cash';
            $counter = $method === 'cash' ? LedgerService::CASH : LedgerService::BANK;
            $type = $data['type'];
            $savingsTransactionId = null;

            if ($type === 'savings' || $type === 'share' || $type === 'contribution') {
                $product = SavingsProduct::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->when($data['savings_product_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
                    ->where('is_active', true)
                    ->orderByRaw("CASE WHEN type = 'mandatory' THEN 0 ELSE 1 END")
                    ->first();

                if (! $product) {
                    throw new BusinessRuleException('No active savings product is configured.');
                }

                $account = $this->savings->openAccount($member, $product);

                $transaction = $this->savings->deposit(
                    $member,
                    $account,
                    $amount,
                    $type === 'share' ? 'contribution' : 'contribution',
                    $counter,
                    $data['reference'] ?? 'MEET-'.$meeting->id,
                    null,
                    'Meeting collection: '.$meeting->title
                );

                $savingsTransactionId = $transaction->id;
            } elseif ($type === 'fine') {
                $fine = Fine::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->when($data['fine_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
                    ->where('member_id', $member->id)
                    ->whereIn('status', ['pending', 'partially_paid'])
                    ->orderBy('due_date')
                    ->first();

                if (! $fine) {
                    $fine = Fine::withoutGlobalScopes()->create([
                        'group_id' => $group->id,
                        'member_id' => $member->id,
                        'meeting_id' => $meeting->id,
                        'type' => 'custom',
                        'amount' => $amount,
                        'amount_paid' => 0,
                        'reason' => 'Meeting fine',
                        'status' => 'pending',
                        'due_date' => now()->toDateString(),
                    ]);
                }

                if ($amount > $fine->outstanding()) {
                    throw new BusinessRuleException('The amount exceeds the outstanding fine.');
                }

                $this->ledger->post($group, 'Fine payment '.$fine->id, [
                    ['code' => $counter, 'debit' => $amount, 'member_id' => $member->id],
                    ['code' => LedgerService::FINES_INCOME, 'credit' => $amount, 'member_id' => $member->id],
                ], now(), $fine, $data['reference'] ?? null);

                $paid = $fine->amount_paid + $amount;
                $fine->update([
                    'amount_paid' => $paid,
                    'status' => $paid >= $fine->amount ? 'paid' : 'partially_paid',
                ]);
            } elseif ($type === 'welfare') {
                $fund = WelfareFund::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->when($data['welfare_fund_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
                    ->where('is_active', true)
                    ->first();

                if (! $fund) {
                    throw new BusinessRuleException('No active welfare fund is configured.');
                }

                $this->welfare->contribute($fund, $member, $amount, $actor, $method, $data['reference'] ?? null);
            } else {
                throw new BusinessRuleException('Unsupported collection type.');
            }

            return MeetingCollection::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'meeting_id' => $meeting->id,
                'member_id' => $member->id,
                'savings_product_id' => $data['savings_product_id'] ?? null,
                'type' => $type,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'savings_transaction_id' => $savingsTransactionId,
                'recorded_by' => $actor->id,
            ]);
        });
    }

    public function complete(Meeting $meeting, string $minutes, User $actor): Meeting
    {
        $meeting->update([
            'minutes' => $minutes,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        AuditService::log('meeting.completed', [
            'meeting_id' => $meeting->id,
            'present' => $meeting->presentCount(),
        ], Group::withoutGlobalScopes()->find($meeting->group_id));

        return $meeting->refresh();
    }
}
