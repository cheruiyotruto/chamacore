<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;

/**
 * Resolves the group administrator's "MGR contribution to savings" policy.
 *
 * When enabled, every merry-go-round contribution also moves a configured
 * portion into the paying member's individual savings account. In split mode
 * the portion comes out of the contribution (500 -> 400 MGR + 100 savings);
 * in add-on mode the full contribution goes to MGR and savings is additional.
 * The portion can be a fixed minor-unit amount or a percentage in basis points.
 */
final class MgrSavingsSettings
{
    public const SETTING_KEY = 'mgr_savings';

    public const MODE_SPLIT = 'split';

    public const MODE_ADD = 'add';

    public const BASIS_FIXED = 'fixed';

    public const BASIS_PERCENT = 'percent';

    /**
     * @var array<string, array{label: string, help: string}>
     */
    public const MODES = [
        self::MODE_SPLIT => [
            'label' => 'Deduct from the MGR contribution',
            'help' => 'A 500 contribution with 100 selected credits 400 to MGR and 100 to savings.',
        ],
        self::MODE_ADD => [
            'label' => 'Add on top of the MGR contribution',
            'help' => 'A 500 contribution with 100 selected credits 500 to MGR and 100 to savings.',
        ],
    ];

    /**
     * @var array<string, string>
     */
    public const BASES = [
        self::BASIS_FIXED => 'Fixed amount',
        self::BASIS_PERCENT => 'Percentage',
    ];

    /**
     * @return array{enabled: bool, mode: string, basis: string, amount: int, rate_bps: int}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'mode' => self::MODE_SPLIT,
            'basis' => self::BASIS_FIXED,
            'amount' => 0,
            'rate_bps' => 0,
        ];
    }

    /**
     * Input amounts are expected in minor units; the form layer converts them.
     *
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, mode: string, basis: string, amount: int, rate_bps: int}
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $mode = is_string($input['mode'] ?? null) ? $input['mode'] : '';
        $basis = is_string($input['basis'] ?? null) ? $input['basis'] : '';

        return [
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $defaults['enabled'],
            'mode' => array_key_exists($mode, self::MODES) ? $mode : $defaults['mode'],
            'basis' => array_key_exists($basis, self::BASES) ? $basis : $defaults['basis'],
            'amount' => max(0, (int) ($input['amount'] ?? $defaults['amount'])),
            'rate_bps' => max(0, min(10000, (int) ($input['rate_bps'] ?? $defaults['rate_bps']))),
        ];
    }

    private array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = self::normalize($config);
    }

    public static function for(?Group $group): self
    {
        if (! $group) {
            return new self;
        }

        $stored = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', self::SETTING_KEY)
            ->first()?->value;

        return new self(is_array($stored) ? $stored : []);
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function mode(): string
    {
        return (string) ($this->config['mode'] ?? self::MODE_SPLIT);
    }

    public function basis(): string
    {
        return (string) ($this->config['basis'] ?? self::BASIS_FIXED);
    }

    public function amount(): int
    {
        return (int) ($this->config['amount'] ?? 0);
    }

    public function rateBps(): int
    {
        return (int) ($this->config['rate_bps'] ?? 0);
    }

    /**
     * Split a contribution into the MGR credit and the automatic savings credit.
     * Both values are minor units and always add up to the amount actually paid
     * in split mode. A split always leaves at least one minor unit for MGR.
     *
     * @return array{mgr: int, savings: int}
     */
    public function allocate(int $contribution): array
    {
        $contribution = max(0, $contribution);

        if (! $this->enabled() || $contribution === 0) {
            return ['mgr' => $contribution, 'savings' => 0];
        }

        $portion = $this->basis() === self::BASIS_PERCENT
            ? (int) round($contribution * $this->rateBps() / 10000)
            : $this->amount();

        $portion = max(0, $portion);

        if ($this->mode() === self::MODE_SPLIT) {
            $portion = min($portion, max(0, $contribution - 1));

            return ['mgr' => $contribution - $portion, 'savings' => $portion];
        }

        return ['mgr' => $contribution, 'savings' => $portion];
    }

    /**
     * @return array{enabled: bool, mode: string, basis: string, amount: int, rate_bps: int}
     */
    public function toArray(): array
    {
        return $this->config;
    }
}
