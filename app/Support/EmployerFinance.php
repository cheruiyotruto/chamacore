<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;

/**
 * Resolves the group administrator's employer-finance loan policy.
 *
 * The workflow is opt-in per group and is only applied to the specific members
 * the administrator selects. Every other member continues to use the normal
 * loan approval route.
 */
class EmployerFinance
{
    public const SETTING_KEY = 'employer_finance';

    public const STATUS_PENDING_FORM = 'pending_form';
    public const STATUS_FORM_UPLOADED = 'form_uploaded';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DECLINED = 'declined';

    /**
     * @return array{enabled: bool, member_ids: array<int, int>, office_name: string, contact_name: string, email: string, phone: string, instructions: string, remit_account_name: string, remit_account_number: string, remit_paybill: string, remit_bank_name: string}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'member_ids' => [],
            'office_name' => '',
            'contact_name' => '',
            'email' => '',
            'phone' => '',
            'instructions' => 'Print this form, take it to your employer\'s finance office for stamping and approval, then upload the stamped copy here.',
            'remit_account_name' => '',
            'remit_account_number' => '',
            'remit_paybill' => '',
            'remit_bank_name' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, member_ids: array<int, int>, office_name: string, contact_name: string, email: string, phone: string, instructions: string, remit_account_name: string, remit_account_number: string, remit_paybill: string, remit_bank_name: string}
     */
    public static function normalize(array $input): array
    {
        $config = self::defaults();

        $config['enabled'] = self::toBool($input['enabled'] ?? $config['enabled']);
        $config['member_ids'] = collect((array) ($input['member_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        $config['office_name'] = trim((string) ($input['office_name'] ?? ''));
        $config['contact_name'] = trim((string) ($input['contact_name'] ?? ''));
        $config['email'] = trim((string) ($input['email'] ?? ''));
        $config['phone'] = trim((string) ($input['phone'] ?? ''));
        $config['instructions'] = trim((string) ($input['instructions'] ?? '')) ?: $config['instructions'];
        $config['remit_account_name'] = trim((string) ($input['remit_account_name'] ?? ''));
        $config['remit_account_number'] = trim((string) ($input['remit_account_number'] ?? ''));
        $config['remit_paybill'] = trim((string) ($input['remit_paybill'] ?? ''));
        $config['remit_bank_name'] = trim((string) ($input['remit_bank_name'] ?? ''));

        return $config;
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

    /**
     * @return array<int, int>
     */
    public function memberIds(): array
    {
        return $this->config['member_ids'] ?? [];
    }

    public function appliesTo(?Member $member): bool
    {
        if (! $member || ! $this->enabled()) {
            return false;
        }

        return in_array((int) $member->id, $this->memberIds(), true);
    }

    public function officeName(): string
    {
        return (string) ($this->config['office_name'] ?? '');
    }

    public function contactName(): string
    {
        return (string) ($this->config['contact_name'] ?? '');
    }

    public function email(): string
    {
        return (string) ($this->config['email'] ?? '');
    }

    public function phone(): string
    {
        return (string) ($this->config['phone'] ?? '');
    }

    public function instructions(): string
    {
        return (string) ($this->config['instructions'] ?? '');
    }

    public function remitAccountName(): string
    {
        return (string) ($this->config['remit_account_name'] ?? '');
    }

    public function remitAccountNumber(): string
    {
        return (string) ($this->config['remit_account_number'] ?? '');
    }

    public function remitPaybill(): string
    {
        return (string) ($this->config['remit_paybill'] ?? '');
    }

    public function remitBankName(): string
    {
        return (string) ($this->config['remit_bank_name'] ?? '');
    }

    public function hasRemittanceDetails(): bool
    {
        return $this->remitAccountNumber() !== '' || $this->remitPaybill() !== '';
    }

    public function hasContact(): bool
    {
        return $this->email() !== '' || $this->phone() !== '';
    }

    /**
     * @return array{enabled: bool, member_ids: array<int, int>, office_name: string, contact_name: string, email: string, phone: string, instructions: string, remit_account_name: string, remit_account_number: string, remit_paybill: string, remit_bank_name: string}
     */
    public function toArray(): array
    {
        return $this->config;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
