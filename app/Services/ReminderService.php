<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Group;
use App\Models\Investment;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundPayout;
use App\Models\ReminderDelivery;
use App\Models\ReminderRule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves admin-configured reminder rules into member deliveries.
 *
 * Platform rules (group_id null) are defaults. A group rule of the same type
 * replaces the platform rule, including when the group rule is disabled.
 */
class ReminderService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function rulesFor(Group $group): Collection
    {
        $rules = ReminderRule::withoutGlobalScopes()
            ->where(function ($query) use ($group) {
                $query->whereNull('group_id')->orWhere('group_id', $group->id);
            })
            ->orderByDesc('group_id')
            ->get();

        $overriddenTypes = $rules
            ->where('group_id', $group->id)
            ->pluck('type');

        return $rules
            ->filter(fn (ReminderRule $rule) => $rule->is_active
                && ($rule->group_id !== null || ! $overriddenTypes->contains($rule->type)))
            ->values();
    }

    /**
     * @return array{sent:int, skipped:int}
     */
    public function sendDue(Group $group, CarbonImmutable $sendDate): array
    {
        $sent = 0;
        $skipped = 0;

        foreach ($this->rulesFor($group) as $rule) {
            foreach ($this->recipients($group, $rule, $sendDate) as $recipient) {
                /** @var Member $member */
                $member = $recipient['member'];

                $exists = ReminderDelivery::withoutGlobalScopes()
                    ->where('reminder_rule_id', $rule->id)
                    ->where('member_id', $member->id)
                    ->whereDate('sent_on', $sendDate->toDateString())
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->notifications->send(
                        $member,
                        $rule->template_key ?: 'admin_reminder',
                        $recipient['data'],
                        $rule->channels ?: null
                    );
                } catch (Throwable $e) {
                    Log::warning('reminder.delivery.failed', [
                        'rule_id' => $rule->id,
                        'member_id' => $member->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                ReminderDelivery::withoutGlobalScopes()->firstOrCreate([
                    'group_id' => $group->id,
                    'reminder_rule_id' => $rule->id,
                    'member_id' => $member->id,
                    'sent_on' => $sendDate->toDateString(),
                ]);

                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * @return array<int, array{member:Member, data:array<string, mixed>}>
     */
    private function recipients(Group $group, ReminderRule $rule, CarbonImmutable $sendDate): array
    {
        $base = [
            'title' => $rule->subject ?: $rule->name,
            'body' => $rule->body ?: $rule->name,
            'subject' => $rule->subject ?: $rule->name,
            'rule_name' => $rule->name,
        ];

        $eventDate = $rule->type === ReminderRule::TYPE_CUSTOM_DATE
            ? ($rule->specific_date?->toDateString())
            : $sendDate->copy()->subDays((int) $rule->offset_days)->toDateString();

        $eventRecipients = [];

        if ($rule->type !== ReminderRule::TYPE_CUSTOM_DATE) {
            $eventRecipients = $this->eventRecipients($group, $rule, $eventDate, $base);
        }

        if ($rule->audience === ReminderRule::AUDIENCE_AUTO) {
            if ($rule->type === ReminderRule::TYPE_CUSTOM_DATE && $eventDate === $sendDate->toDateString()) {
                return $this->memberRecipients($group, $rule, $base);
            }

            return $eventRecipients;
        }

        if ($rule->audience === ReminderRule::AUDIENCE_SELECTED) {
            return collect($eventRecipients)
                ->filter(fn ($recipient) => in_array(
                    $recipient['member']->id,
                    array_map('intval', $rule->member_ids ?? []),
                    true
                ))
                ->all();
        }

        return $this->memberRecipients($group, $rule, $base, $eventRecipients);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<int, array{member:Member, data:array<string, mixed>}>
     */
    private function memberRecipients(
        Group $group,
        ReminderRule $rule,
        array $base,
        array $eventRecipients = []
    ): array {
        $members = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->when($rule->audience === ReminderRule::AUDIENCE_SELECTED, fn ($query) => $query->whereIn(
                'id',
                array_map('intval', $rule->member_ids ?? [])
            ))
            ->with('user')
            ->orderBy('id')
            ->get();

        return $members
            ->mapWithKeys(function (Member $member) use ($base, $eventRecipients) {
                $data = $eventRecipients[$member->id]['data'] ?? $base;

                return [$member->id => ['member' => $member, 'data' => $data]];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<int, array{member:Member, data:array<string, mixed>}>
     */
    private function eventRecipients(Group $group, ReminderRule $rule, string $eventDate, array $base): array
    {
        $recipients = [];

        $add = function (?Member $member, array $data) use (&$recipients) {
            if (! $member || $member->status !== 'active' || isset($recipients[$member->id])) {
                return;
            }

            $recipients[$member->id] = ['member' => $member, 'data' => $data];
        };

        switch ($rule->type) {
            case ReminderRule::TYPE_MGR_CONTRIBUTION:
                MerryGoRoundContribution::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->whereIn('status', ['pending', 'late'])
                    ->whereDate('due_date', $eventDate)
                    ->with(['member', 'cycle'])
                    ->orderBy('id')
                    ->each(function (MerryGoRoundContribution $contribution) use ($add, $base) {
                        $add($contribution->member, [
                            ...$base,
                            'cycle' => $contribution->cycle?->name ?? 'Merry-go-round',
                            'amount' => Money::format((int) $contribution->amount),
                            'due_date' => $contribution->due_date?->format('d M Y') ?? '',
                        ]);
                    });
                break;

            case ReminderRule::TYPE_MGR_PAYOUT:
                MerryGoRoundPayout::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('status', 'pending')
                    ->whereDate('scheduled_date', $eventDate)
                    ->with(['member', 'cycle'])
                    ->orderBy('id')
                    ->each(function (MerryGoRoundPayout $payout) use ($add, $base) {
                        $add($payout->member, [
                            ...$base,
                            'cycle' => $payout->cycle?->name ?? 'Merry-go-round',
                            'amount' => Money::format((int) $payout->amount),
                            'payout_date' => $payout->scheduled_date?->format('d M Y') ?? '',
                        ]);
                    });
                break;

            case ReminderRule::TYPE_LOAN_REPAYMENT:
                LoanSchedule::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->whereDate('due_date', $eventDate)
                    ->with(['loan.member'])
                    ->orderBy('id')
                    ->each(function (LoanSchedule $schedule) use ($add, $base) {
                        $loan = $schedule->loan;

                        if (! $loan || ! $loan->isActive()) {
                            return;
                        }

                        $add($loan->member, [
                            ...$base,
                            'loan_no' => $loan->loan_no,
                            'amount' => Money::format($schedule->outstanding()),
                            'due_date' => $schedule->due_date?->format('d M Y') ?? '',
                        ]);
                    });
                break;

            case ReminderRule::TYPE_BILL_DUE:
                Bill::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->whereNotNull('member_id')
                    ->whereIn('status', ['pending', 'partially_paid', 'overdue'])
                    ->whereDate('due_date', $eventDate)
                    ->with('member')
                    ->orderBy('id')
                    ->each(function (Bill $bill) use ($add, $base) {
                        $add($bill->member, [
                            ...$base,
                            'bill_no' => $bill->bill_no,
                            'payee' => $bill->payeeLabel(),
                            'amount' => Money::format($bill->outstanding()),
                            'due_date' => $bill->due_date?->format('d M Y') ?? '',
                        ]);
                    });
                break;

            case ReminderRule::TYPE_INVESTMENT_MATURITY:
                Investment::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('status', Investment::STATUS_ACTIVE)
                    ->whereDate('maturity_date', $eventDate)
                    ->with(['member', 'product'])
                    ->orderBy('id')
                    ->each(function (Investment $investment) use ($add, $base) {
                        $add($investment->member, [
                            ...$base,
                            'product' => $investment->product?->name ?? 'Investment',
                            'reference_no' => $investment->reference_no,
                            'amount' => Money::format((int) $investment->principal + (int) $investment->expected_interest),
                            'maturity_date' => $investment->maturity_date?->format('d M Y') ?? '',
                        ]);
                    });
                break;
        }

        return $recipients;
    }
}
