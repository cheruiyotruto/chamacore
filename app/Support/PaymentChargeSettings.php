<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\PlatformSetting;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Resolves the effective payment-charge rules for a group.
 *
 * Platform rules are the defaults. A group rule with the same category key
 * overrides the matching platform fields; fields omitted from a group rule are
 * inherited. Every category can therefore be enabled or disabled at either
 * level without destroying the other level's configuration.
 */
final class PaymentChargeSettings
{
    public const KEY = 'payment_charges';

    public const BILL_PAYMENTS = 'bill_payments';
    public const MPESA_PAYMENTS = 'mpesa_payments';
    public const WALLET_DEPOSITS = 'wallet_deposits';
    public const PAYOUTS = 'payouts';
    public const FLOAT_PURCHASES = 'float_purchases';
    public const ALL_PAYMENTS = 'all_payments';

    public const CATEGORIES = [
        self::BILL_PAYMENTS => 'Bill payments',
        self::MPESA_PAYMENTS => 'All M-Pesa payments',
        self::WALLET_DEPOSITS => 'Wallet deposits',
        self::PAYOUTS => 'Bank transfers and payouts',
        self::FLOAT_PURCHASES => 'M-Pesa float purchases',
        self::ALL_PAYMENTS => 'Every payment',
    ];

    public const MODE_PERCENT = 'percent';
    public const MODE_FIXED = 'fixed';
    public const MODE_FIXED_PERCENT = 'fixed_percent';
    public const MODE_MANUAL = 'manual';

    public const MODES = [
        self::MODE_PERCENT => 'Percentage of amount',
        self::MODE_FIXED => 'Fixed amount',
        self::MODE_FIXED_PERCENT => 'Fixed amount + percentage',
        self::MODE_MANUAL => 'Entered manually per payment',
    ];

    public const BORNE_BY_PAYER = 'payer';
    public const BORNE_BY_PAYEE = 'payee';
    public const BORNE_BY_GROUP = 'group';

    public const BORNE_BY = [
        self::BORNE_BY_PAYER => 'Payer pays on top',
        self::BORNE_BY_PAYEE => 'Deduct from recipient',
        self::BORNE_BY_GROUP => 'Group absorbs the cost',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $rule = [
            'enabled' => false,
            'mode' => self::MODE_PERCENT,
            'amount' => 0,
            'rate_bps' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'borne_by' => self::BORNE_BY_PAYER,
            'income_account_code' => LedgerService::TRANSACTION_CHARGE_INCOME,
        ];

        return array_fill_keys(array_keys(self::CATEGORIES), $rule);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function platform(): array
    {
        $stored = PlatformSetting::query()->where('key', self::KEY)->first()?->value;

        return self::merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forGroup(Group $group): array
    {
        return self::merge(self::platform(), self::groupOverride($group));
    }

    /**
     * Nearest configured override wins: branch, parent branch, head office.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function groupOverride(Group $group): array
    {
        $hierarchy = $group->hierarchyIds();

        $stored = GroupSetting::withoutGlobalScopes()
            ->whereIn('group_id', $hierarchy)
            ->where('key', self::KEY)
            ->pluck('value', 'group_id');

        foreach ($hierarchy as $groupId) {
            $value = $stored->get($groupId);

            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $input): array
    {
        $rules = [];

        foreach (self::CATEGORIES as $key => $label) {
            $rule = is_array($input[$key] ?? null) ? $input[$key] : [];

            $mode = in_array($rule['mode'] ?? null, array_keys(self::MODES), true)
                ? (string) $rule['mode']
                : self::MODE_PERCENT;

            $borneBy = in_array($rule['borne_by'] ?? null, array_keys(self::BORNE_BY), true)
                ? (string) $rule['borne_by']
                : self::BORNE_BY_PAYER;

            $rules[$key] = [
                'enabled' => (bool) ($rule['enabled'] ?? false),
                'mode' => $mode,
                'amount' => max(0, (int) ($rule['amount'] ?? 0)),
                'rate_bps' => max(0, min(10000, (int) ($rule['rate_bps'] ?? 0))),
                'min_amount' => max(0, (int) ($rule['min_amount'] ?? 0)),
                'max_amount' => max(0, (int) ($rule['max_amount'] ?? 0)),
                'borne_by' => $borneBy,
                'income_account_code' => (string) ($rule['income_account_code'] ?? LedgerService::TRANSACTION_CHARGE_INCOME),
            ];
        }

        return $rules;
    }

    /**
     * Validate a settings form and convert money fields to minor units.
     *
     * @param  array<int, string>  $incomeCodes
     * @return array<string, array<string, mixed>>
     */
    public static function fromRequest(Request $request, array $incomeCodes = []): array
    {
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.enabled' => ['nullable', 'boolean'],
            'rules.*.mode' => ['required', Rule::in(array_keys(self::MODES))],
            'rules.*.amount' => ['nullable', 'numeric', 'min:0'],
            'rules.*.rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rules.*.min_amount' => ['nullable', 'numeric', 'min:0'],
            'rules.*.max_amount' => ['nullable', 'numeric', 'min:0'],
            'rules.*.borne_by' => ['required', Rule::in(array_keys(self::BORNE_BY))],
            'rules.*.income_account_code' => [
                'nullable', 'string',
                Rule::in($incomeCodes ?: [LedgerService::TRANSACTION_CHARGE_INCOME]),
            ],
        ]);

        $rules = [];

        foreach (self::CATEGORIES as $key => $label) {
            $rule = $data['rules'][$key] ?? [];

            $rules[$key] = [
                'enabled' => (bool) ($rule['enabled'] ?? false),
                'mode' => (string) ($rule['mode'] ?? self::MODE_PERCENT),
                'amount' => isset($rule['amount']) && $rule['amount'] !== ''
                    ? Money::toMinor($rule['amount'])
                    : 0,
                'rate_bps' => (int) ($rule['rate_bps'] ?? 0),
                'min_amount' => isset($rule['min_amount']) && $rule['min_amount'] !== ''
                    ? Money::toMinor($rule['min_amount'])
                    : 0,
                'max_amount' => isset($rule['max_amount']) && $rule['max_amount'] !== ''
                    ? Money::toMinor($rule['max_amount'])
                    : 0,
                'borne_by' => (string) ($rule['borne_by'] ?? self::BORNE_BY_PAYER),
                'income_account_code' => (string) ($rule['income_account_code'] ?? LedgerService::TRANSACTION_CHARGE_INCOME),
            ];
        }

        return self::normalize($rules);
    }

    /**
     * Categories triggered by a payment. More than one rule can apply; when
     * they do, the charges are additive (the same way transaction charges
     * behave at the teller desk).
     *
     * @return array<int, string>
     */
    public static function categoriesFor(string $purpose, string $provider = 'mpesa', ?string $method = null): array
    {
        $categories = [self::ALL_PAYMENTS];

        if ($provider === 'mpesa' || $method === 'mpesa') {
            $categories[] = self::MPESA_PAYMENTS;
        }

        if (in_array($purpose, ['wallet_topup', 'wallet_deposit'], true)) {
            $categories[] = self::WALLET_DEPOSITS;
        }

        if ($purpose === 'bill_payment') {
            $categories[] = self::BILL_PAYMENTS;
        }

        if ($purpose === 'payout') {
            $categories[] = self::PAYOUTS;
        }

        if ($purpose === 'float_purchase') {
            $categories[] = self::FLOAT_PURCHASES;
        }

        return array_values(array_unique($categories));
    }

    /**
     * @param  array<string, array<string, mixed>>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $rule) {
            if (! array_key_exists($key, $base) || ! is_array($rule)) {
                continue;
            }

            $base[$key] = array_merge($base[$key], $rule);
        }

        return $base;
    }
}
