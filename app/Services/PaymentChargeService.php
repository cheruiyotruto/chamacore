<?php

namespace App\Services;

use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Support\GroupContext;
use App\Support\PaymentChargeSettings;
use App\Support\PlanAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Calculates and posts opt-in charges for payment flows.
 *
 * A quote records three buckets: fees the payer pays on top, fees deducted
 * from the recipient and fees absorbed by the group. All money stays inside a
 * balanced double-entry journal; the underlying payment services decide which
 * counter account supplied the cash.
 */
class PaymentChargeService
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * @param  array<int, string>  $categories
     * @return array<string, mixed>
     */
    public function quote(Group $group, int $baseAmount, array $categories, ?int $manualAmount = null): array
    {
        $settings = PaymentChargeSettings::forGroup($group);
        $context = app(GroupContext::class);
        $canSetManual = $context->isStaff() && (
            $context->hasPermission('payments.initiate')
            || $context->hasPermission('payouts.manage')
            || $context->hasPermission('settings.manage')
        );
        $lines = [];
        $buckets = [
            PaymentChargeSettings::BORNE_BY_PAYER => 0,
            PaymentChargeSettings::BORNE_BY_PAYEE => 0,
            PaymentChargeSettings::BORNE_BY_GROUP => 0,
        ];

        foreach ($categories as $category) {
            $rule = $settings[$category] ?? null;

            if (! $rule || ! $rule['enabled']) {
                continue;
            }

            $amount = match ($rule['mode']) {
                PaymentChargeSettings::MODE_FIXED => (int) $rule['amount'],
                PaymentChargeSettings::MODE_FIXED_PERCENT => (int) $rule['amount']
                    + (int) round($baseAmount * (int) $rule['rate_bps'] / 10000),
                PaymentChargeSettings::MODE_MANUAL => $canSetManual ? max(0, (int) ($manualAmount ?? 0)) : 0,
                default => (int) round($baseAmount * (int) $rule['rate_bps'] / 10000),
            };

            if ($rule['min_amount'] > 0) {
                $amount = max($amount, (int) $rule['min_amount']);
            }

            if ($rule['max_amount'] > 0) {
                $amount = min($amount, (int) $rule['max_amount']);
            }

            $amount = max(0, $amount);

            // A recipient can never be deducted more than the amount paid.
            if ($rule['borne_by'] === PaymentChargeSettings::BORNE_BY_PAYEE) {
                $amount = min($amount, max(0, $baseAmount - $buckets[$rule['borne_by']]));
            }

            if ($amount === 0) {
                continue;
            }

            $buckets[$rule['borne_by']] += $amount;

            $lines[] = [
                'category' => $category,
                'name' => PaymentChargeSettings::CATEGORIES[$category] ?? $category,
                'mode' => $rule['mode'],
                'borne_by' => $rule['borne_by'],
                'amount' => $amount,
                'income_account_code' => (string) $rule['income_account_code'],
            ];
        }

        $payerFee = $buckets[PaymentChargeSettings::BORNE_BY_PAYER];
        $payeeFee = $buckets[PaymentChargeSettings::BORNE_BY_PAYEE];
        $groupFee = $buckets[PaymentChargeSettings::BORNE_BY_GROUP];

        return [
            'lines' => $lines,
            'payer_fee' => $payerFee,
            'payee_fee' => $payeeFee,
            'group_fee' => $groupFee,
            'total_fee' => $payerFee + $payeeFee + $groupFee,
            'base_amount' => $baseAmount,
            'payable_by_payer' => $baseAmount + $payerFee,
            'net_to_payee' => max(0, $baseAmount - $payeeFee),
            // The group-borne fee is funded from the group's counter account,
            // not from the payer's wallet, so it never reduces the payer debit.
            'payer_net_out' => $baseAmount + $payerFee,
        ];
    }

    /**
     * Post only the fee legs when a domain service has already posted the base
     * transaction. The caller must pass the net amount to that service so the
     * combined journals remain balanced.
     *
     * @param  array<string, mixed>  $quote
     * @return array<int, JournalEntry>
     */
    public function postFees(
        Group $group,
        array $quote,
        string $counterCode,
        ?Member $payer,
        string $reference,
        ?Model $source = null
    ): array {
        $journals = [];

        $collected = (int) ($quote['payer_fee'] ?? 0) + (int) ($quote['payee_fee'] ?? 0);
        $groupFee = (int) ($quote['group_fee'] ?? 0);

        if ($collected > 0) {
            $lines = [[
                'code' => $counterCode,
                'debit' => $collected,
                'member_id' => $payer?->id,
                'description' => 'Payment charges collected',
            ]];

            foreach ($this->incomeLines($quote, $group) as $code => $amount) {
                $lines[] = [
                    'code' => $code,
                    'credit' => $amount,
                    'member_id' => $payer?->id,
                    'description' => 'Payment charge income',
                ];
            }

            $journals[] = $this->ledger->post(
                $group,
                'Payment charges · '.$reference,
                $lines,
                null,
                $source,
                $reference
            );
        }

        if ($groupFee > 0) {
            $journals[] = $this->ledger->post($group, 'Group-borne payment charge · '.$reference, [
                ['code' => LedgerService::OPERATING_EXPENSE, 'debit' => $groupFee, 'description' => 'Payment charge borne by group'],
                ['code' => $counterCode, 'credit' => $groupFee, 'member_id' => $payer?->id, 'description' => 'Payment charge paid by group'],
            ], null, $source, $reference);
        }

        return $journals;
    }

    /**
     * Post the complete payment (base plus every charge) as one journal. Used
     * by bill payments and internal wallet transfers where no other service
     * posts a base journal.
     *
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $options
     */
    public function postPaymentJournal(Group $group, array $quote, array $options): JournalEntry
    {
        $base = (int) ($quote['base_amount'] ?? 0);
        $counterCode = (string) ($options['counter_code'] ?? LedgerService::BANK);
        $payer = $options['payer'] ?? null;
        $payee = $options['payee'] ?? null;
        $payeeCode = (string) ($options['payee_account_code'] ?? LedgerService::BILL_PAYABLE);
        $reference = (string) ($options['reference'] ?? 'PAYMENT');
        $description = (string) ($options['description'] ?? 'Bill payment');
        $source = $options['source'] ?? null;

        $lines = [[
            'code' => $counterCode,
            'debit' => (int) ($quote['payable_by_payer'] ?? $base),
            'member_id' => $payer?->id,
            'description' => 'Payment received',
        ]];

        $netToPayee = (int) ($quote['net_to_payee'] ?? $base);

        if ($netToPayee > 0) {
            $lines[] = [
                'code' => $payee instanceof Member ? LedgerService::WALLET_LIABILITY : $payeeCode,
                'credit' => $netToPayee,
                'member_id' => $payee?->id,
                'description' => 'Payment credited to recipient',
            ];
        }

        foreach ($this->incomeLines($quote, $group) as $code => $amount) {
            $lines[] = [
                'code' => $code,
                'credit' => $amount,
                'member_id' => null,
                'description' => 'Payment charge income',
            ];
        }

        $groupFee = (int) ($quote['group_fee'] ?? 0);

        if ($groupFee > 0) {
            $lines[] = [
                'code' => LedgerService::OPERATING_EXPENSE,
                'debit' => $groupFee,
                'description' => 'Payment charge borne by group',
            ];
            $lines[] = [
                'code' => $counterCode,
                'credit' => $groupFee,
                'member_id' => $payer?->id,
                'description' => 'Payment charge paid by group',
            ];
        }

        return $this->ledger->post($group, $description, $lines, null, $source, $reference);
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, int>
     */
    public function incomeLines(array $quote, ?Group $group = null): array
    {
        $totals = [];

        foreach ($quote['lines'] ?? [] as $line) {
            if (! in_array($line['borne_by'] ?? null, [
                PaymentChargeSettings::BORNE_BY_PAYER,
                PaymentChargeSettings::BORNE_BY_PAYEE,
            ], true)) {
                continue;
            }

            $code = (string) ($line['income_account_code'] ?? LedgerService::TRANSACTION_CHARGE_INCOME);

            // Platform-managed groups have no monthly subscription. Their super
            // admin sets the charge rules and the income is collected in the
            // platform's own report/account instead of the group's books.
            if ($group && PlanAccess::chargesToPlatform($group)) {
                $code = LedgerService::PLATFORM_CHARGE_INCOME;
            }

            $totals[$code] = ($totals[$code] ?? 0) + (int) ($line['amount'] ?? 0);
        }

        return array_filter($totals);
    }
}
