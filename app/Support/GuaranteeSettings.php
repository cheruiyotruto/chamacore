<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Guarantor;

/**
 * Resolves the group administrator's guarantee-cover policy.
 *
 * A guarantee can cover only the principal or the whole scheduled repayment
 * obligation (principal, interest and configured loan fees). The same choice
 * can be applied to existing commitments when a member's available guarantee
 * capacity is calculated. It also controls whether accepting a guarantee must
 * be signed in the member portal and which notification channels deliver the
 * guarantor request.
 */
final class GuaranteeSettings
{
    public const SETTING_KEY = 'guarantee_settings';

    public const COVERAGE_PRINCIPAL = 'principal';

    public const COVERAGE_FULL_OBLIGATION = 'full_obligation';

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_EMAIL = 'email';

    /**
     * @var array<int, string>
     */
    public const CHANNELS = [
        self::CHANNEL_IN_APP,
        self::CHANNEL_SMS,
        self::CHANNEL_PUSH,
        self::CHANNEL_EMAIL,
    ];

    /**
     * @var array<string, string>
     */
    public const CHANNEL_LABELS = [
        self::CHANNEL_IN_APP => 'In-app notification',
        self::CHANNEL_SMS => 'SMS',
        self::CHANNEL_PUSH => 'Push notification',
        self::CHANNEL_EMAIL => 'Email',
    ];

    /**
     * @var array<string, array{label: string, help: string}>
     */
    public const MODES = [
        self::COVERAGE_PRINCIPAL => [
            'label' => 'Principal only',
            'help' => 'Guarantees cover the loan principal. Interest and fees are excluded.',
        ],
        self::COVERAGE_FULL_OBLIGATION => [
            'label' => 'Principal, interest and fees',
            'help' => 'Guarantees cover the full scheduled repayment obligation. Late penalties are not reserved.',
        ],
    ];

    /**
     * @return array{new_guarantees: string, existing_commitments: string, require_signature: bool, notification_channels: array<int, string>}
     */
    public static function defaults(): array
    {
        return [
            'new_guarantees' => self::COVERAGE_FULL_OBLIGATION,
            'existing_commitments' => self::COVERAGE_FULL_OBLIGATION,
            'require_signature' => true,
            'notification_channels' => self::CHANNELS,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{new_guarantees: string, existing_commitments: string, require_signature: bool, notification_channels: array<int, string>}
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $channels = $input['notification_channels'] ?? null;

        return [
            'new_guarantees' => self::mode($input['new_guarantees'] ?? null, $defaults['new_guarantees']),
            'existing_commitments' => self::mode(
                $input['existing_commitments'] ?? null,
                $defaults['existing_commitments']
            ),
            'require_signature' => array_key_exists('require_signature', $input)
                ? (bool) $input['require_signature']
                : $defaults['require_signature'],
            'notification_channels' => is_array($channels)
                ? array_values(array_unique(array_filter(
                    $channels,
                    fn ($channel) => is_string($channel) && in_array($channel, self::CHANNELS, true)
                )))
                : $defaults['notification_channels'],
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

    public function newGuarantees(): string
    {
        return (string) ($this->config['new_guarantees'] ?? self::COVERAGE_FULL_OBLIGATION);
    }

    public function existingCommitments(): string
    {
        return (string) ($this->config['existing_commitments'] ?? self::COVERAGE_FULL_OBLIGATION);
    }

    public function coversFullObligationForNewGuarantees(): bool
    {
        return $this->newGuarantees() === self::COVERAGE_FULL_OBLIGATION;
    }

    public function countsFullObligationForExistingCommitments(): bool
    {
        return $this->existingCommitments() === self::COVERAGE_FULL_OBLIGATION;
    }

    public function requiresSignature(): bool
    {
        return (bool) ($this->config['require_signature'] ?? true);
    }

    /**
     * Channels the group admin has enabled for guarantor request messages.
     *
     * @return array<int, string>
     */
    public function notificationChannels(): array
    {
        $channels = $this->config['notification_channels'] ?? self::CHANNELS;

        return is_array($channels) ? array_values($channels) : self::CHANNELS;
    }

    /**
     * The amount this commitment should consume from a guarantor's capacity.
     */
    public function commitmentAmount(Guarantor $guarantor): int
    {
        if ($this->countsFullObligationForExistingCommitments()) {
            return (int) ($guarantor->full_obligation_amount ?? $guarantor->guaranteed_amount);
        }

        return (int) ($guarantor->principal_amount ?? $guarantor->guaranteed_amount);
    }

    /**
     * @return array{new_guarantees: string, existing_commitments: string, require_signature: bool, notification_channels: array<int, string>}
     */
    public function toArray(): array
    {
        return $this->config;
    }

    private static function mode(mixed $value, string $default): string
    {
        $mode = is_string($value) ? $value : '';

        return array_key_exists($mode, self::MODES) ? $mode : $default;
    }
}
