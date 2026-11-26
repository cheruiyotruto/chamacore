<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Services\PayoutService;
use Illuminate\Support\Str;

/**
 * Admin-controlled visibility/access for member portal sections.
 *
 * `members.portal_sections` stores per-member overrides as:
 *   {"disabled": ["loans", "wallet", ...]}
 * Null/empty means all sections are enabled (backward compatible). Some
 * sections, such as documents, also require a group-level opt-in before they
 * appear in the member portal.
 */
class PortalAccess
{
    public const MEMBER_DOCUMENTS_SETTING = 'member_portal_documents_enabled';

    public const SECTIONS = [
        'savings' => [
            'label' => 'Savings',
            'icon' => 'banknotes',
            'route' => 'portal.savings.index',
            'patterns' => ['portal.savings.*'],
        ],
        'investments' => [
            'label' => 'Investments',
            'icon' => 'trending-up',
            'route' => 'portal.investments.index',
            'patterns' => ['portal.investments.*'],
        ],
        'loans' => [
            'label' => 'Loans',
            'icon' => 'credit-card',
            'route' => 'portal.loans.index',
            'patterns' => ['portal.loans.*', 'portal.guarantees.*'],
        ],
        'wallet' => [
            'label' => 'Wallet',
            'icon' => 'wallet',
            'route' => 'portal.wallet.show',
            'patterns' => ['portal.wallet.*'],
        ],
        'bills' => [
            'label' => 'Bills',
            'icon' => 'receipt',
            'route' => 'portal.bills.index',
            'patterns' => ['portal.bills.*'],
        ],
        'bank_transfers' => [
            'label' => 'Bank transfers',
            'icon' => 'banknotes',
            'route' => 'portal.bank-transfers.index',
            'patterns' => ['portal.bank-transfers.*'],
        ],
        'float_purchases' => [
            'label' => 'Buy M-Pesa float',
            'icon' => 'wallet',
            'route' => 'portal.float.index',
            'patterns' => ['portal.float.*'],
        ],
        'mgr' => [
            'label' => 'Merry-go-round',
            'icon' => 'calendar',
            'route' => 'portal.mgr.index',
            'patterns' => ['portal.mgr.*'],
        ],
        'documents' => [
            'label' => 'Documents',
            'icon' => 'folder',
            'route' => 'portal.documents.index',
            'patterns' => ['portal.documents.*'],
        ],
        'governance' => [
            'label' => 'Governance',
            'icon' => 'scale',
            'route' => 'portal.governance.index',
            'patterns' => ['portal.governance.*'],
        ],
        'support' => [
            'label' => 'Support',
            'icon' => 'chat',
            'route' => 'portal.support.index',
            'patterns' => ['portal.support.*'],
        ],
        'invitations' => [
            'label' => 'Invite',
            'icon' => 'users',
            'route' => 'portal.invitations.index',
            'patterns' => ['portal.invitations.*'],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function sectionKeys(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * @param  array<int, string>|null  $disabled
     * @return array{disabled: array<int, string>}
     */
    public static function normalize(?array $disabled): array
    {
        $disabled = collect($disabled ?? [])
            ->filter(fn ($key) => is_string($key) && array_key_exists($key, self::SECTIONS))
            ->unique()
            ->values()
            ->all();

        return ['disabled' => $disabled];
    }

    /**
     * @return array<int, string>
     */
    public static function disabledSections(?Member $member): array
    {
        if (! $member) {
            return [];
        }

        $value = $member->portal_sections;

        if (! is_array($value)) {
            return [];
        }

        $disabled = $value['disabled'] ?? $value;

        if (! is_array($disabled)) {
            return [];
        }

        return collect($disabled)
            ->filter(fn ($key) => is_string($key) && array_key_exists($key, self::SECTIONS))
            ->unique()
            ->values()
            ->all();
    }

    public static function sectionEnabled(?Member $member, string $section): bool
    {
        if (! array_key_exists($section, self::SECTIONS)) {
            return true;
        }

        return ! in_array($section, self::disabledSections($member), true);
    }

    /**
     * Member document access is opt-in for the whole group. Group officials can
     * still work with documents in the staff area; this only controls the member
     * portal.
     */
    public static function memberDocumentsEnabled(?Group $group): bool
    {
        if (! $group) {
            return false;
        }

        $setting = GroupSetting::withoutGlobalScopes()
            ->forGroup($group->id)
            ->where('key', self::MEMBER_DOCUMENTS_SETTING)
            ->first();

        return $setting?->value === true;
    }

    public static function canAccessSection(?Group $group, ?Member $member, string $section): bool
    {
        if (! $member || ! self::sectionEnabled($member, $section)) {
            return false;
        }

        if ($section === 'documents') {
            return self::memberDocumentsEnabled($group);
        }

        if ($section === 'bank_transfers') {
            return $group ? PayoutService::memberAccessEnabled($group, $member) : false;
        }

        if ($section === 'float_purchases') {
            return $group ? FloatAccess::memberAccessEnabled($group, $member) : false;
        }

        return true;
    }

    public static function sectionForRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        foreach (self::SECTIONS as $key => $section) {
            foreach ($section['patterns'] as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function allowsRoute(?Group $group, ?Member $member, ?string $routeName): bool
    {
        $section = self::sectionForRoute($routeName);

        if (! $section) {
            return true;
        }

        return self::canAccessSection($group, $member, $section);
    }
}
