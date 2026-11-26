<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Group extends \Illuminate\Database\Eloquent\Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const PLATFORM_MODE_SELF = 'self_managed';
    public const PLATFORM_MODE_OVERSIGHT = 'oversight';
    public const PLATFORM_MODE_FULL = 'full';

    public const PLATFORM_MODES = [
        self::PLATFORM_MODE_SELF => 'Self-managed (platform off)',
        self::PLATFORM_MODE_OVERSIGHT => 'Platform oversight',
        self::PLATFORM_MODE_FULL => 'Full platform control',
    ];

    protected $fillable = [
        'uuid', 'parent_group_id', 'name', 'slug', 'is_branch', 'branch_code',
        'registration_no', 'email', 'phone', 'county', 'sub_county', 'address',
        'currency', 'timezone', 'logo_path', 'status', 'subscription_status',
        'platform_management_mode', 'platform_managed_at', 'platform_managed_by',
        'trial_ends_at', 'fiscal_year_start_month', 'settings', 'description', 'created_by',
        'public_join_enabled', 'public_join_summary',
    ];

    protected function casts(): array
    {
        return [
            'is_branch' => 'boolean',
            'public_join_enabled' => 'boolean',
            'settings' => 'array',
            'platform_managed_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Group $group) {
            $group->uuid ??= (string) Str::uuid();
            $group->slug ??= Str::slug($group->name).'-'.Str::lower(Str::random(4));

            if ($group->is_branch && ! $group->branch_code) {
                $group->branch_code = self::generateBranchCode($group->name, (int) $group->parent_group_id);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'parent_group_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Group::class, 'parent_group_id');
    }

    public function activeBranches(): HasMany
    {
        return $this->branches()->where('status', 'active');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function savingsProducts(): HasMany
    {
        return $this->hasMany(SavingsProduct::class);
    }

    public function loanProducts(): HasMany
    {
        return $this->hasMany(LoanProduct::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeRoot($query)
    {
        return $query->where('is_branch', false);
    }

    public function scopeBranches($query)
    {
        return $query->where('is_branch', true);
    }

    /**
     * Organisations that a super admin has explicitly opted into public
     * membership applications. Branches are deliberately excluded so a private
     * tenant is never exposed through the directory by accident.
     */
    public function scopePubliclyJoinable($query)
    {
        return $query
            ->where('status', 'active')
            ->where('public_join_enabled', true)
            ->where('is_branch', false)
            ->whereIn('platform_management_mode', [
                self::PLATFORM_MODE_OVERSIGHT,
                self::PLATFORM_MODE_FULL,
            ]);
    }

    public function isPubliclyJoinable(): bool
    {
        return $this->isActive()
            && (bool) $this->public_join_enabled
            && $this->isHeadOffice()
            && $this->isPlatformManaged();
    }

    public function isBranch(): bool
    {
        return (bool) $this->is_branch;
    }

    public function isHeadOffice(): bool
    {
        return ! $this->isBranch();
    }

    public function platformManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_managed_by');
    }

    /**
     * Effective platform management mode. A branch inherits the nearest
     * configured ancestor unless it explicitly opts out with self_managed.
     */
    public function platformManagementMode(): ?string
    {
        foreach ($this->hierarchyIds() as $groupId) {
            $candidate = $groupId === $this->id
                ? $this
                : Group::withoutGlobalScopes()->find($groupId);

            $mode = $candidate?->platform_management_mode;

            if (in_array($mode, array_keys(self::PLATFORM_MODES), true)) {
                return $mode;
            }
        }

        return null;
    }

    public function platformManagementLabel(): string
    {
        return self::PLATFORM_MODES[$this->platformManagementMode() ?? self::PLATFORM_MODE_SELF];
    }

    public function isPlatformManaged(): bool
    {
        return in_array($this->platformManagementMode(), [
            self::PLATFORM_MODE_OVERSIGHT,
            self::PLATFORM_MODE_FULL,
        ], true);
    }

    public function allowsPlatformOversight(): bool
    {
        return $this->isPlatformManaged();
    }

    public function allowsFullPlatformControl(): bool
    {
        return $this->platformManagementMode() === self::PLATFORM_MODE_FULL;
    }

    /**
     * IDs of all groups whose income is reported to the platform section.
     *
     * @return array<int, int>
     */
    public static function platformManagedGroupIds(): array
    {
        return Group::withoutGlobalScopes()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => Group::withoutGlobalScopes()->find($id)?->isPlatformManaged())
            ->values()
            ->all();
    }

    /**
     * The top-level organisation that owns this branch (or this group itself).
     */
    public function root(): Group
    {
        $current = $this;
        $seen = [$current->id => true];

        while ($current->parent_group_id) {
            $parent = Group::withoutGlobalScopes()->find($current->parent_group_id);

            if (! $parent || isset($seen[$parent->id])) {
                break;
            }

            $seen[$parent->id] = true;
            $current = $parent;
        }

        return $current;
    }

    public function billingOwner(): Group
    {
        return $this->root();
    }

    public function rootId(): int
    {
        return $this->root()->id;
    }

    /**
     * @return array<int, int>
     */
    public function ancestorIds(): array
    {
        $ids = [];
        $current = $this->parent_group_id;
        $seen = [$this->id => true];

        while ($current) {
            if (isset($seen[$current])) {
                break;
            }

            $ids[] = (int) $current;
            $seen[$current] = true;

            $parent = Group::withoutGlobalScopes()->find($current);
            $current = $parent?->parent_group_id;
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    public function hierarchyIds(): array
    {
        return array_values(array_unique([$this->id, ...$this->ancestorIds()]));
    }

    /**
     * All descendant branch IDs, including indirect children.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $queue = [$this->id];
        $seen = [$this->id => true];

        while ($queue) {
            $parentId = array_shift($queue);

            $children = Group::withoutGlobalScopes()
                ->where('parent_group_id', $parentId)
                ->pluck('id');

            foreach ($children as $childId) {
                $childId = (int) $childId;

                if (isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    public function visibleGroupIds(): array
    {
        return array_values(array_unique([$this->id, ...$this->descendantIds()]));
    }

    public function isDescendantOf(Group $group): bool
    {
        return in_array($group->id, $this->ancestorIds(), true);
    }

    public function branchPath(): string
    {
        $names = Group::withoutGlobalScopes()
            ->whereIn('id', $this->hierarchyIds())
            ->pluck('name', 'id');

        return implode(' / ', collect($this->hierarchyIds())
            ->map(fn (int $id) => $names->get($id))
            ->filter()
            ->reverse()
            ->all());
    }

    public function branchLabel(): string
    {
        return $this->branch_code ? $this->branch_code.' · '.$this->name : $this->name;
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->billingOwner()->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        // Nearest configuration wins: branch, parent branch, head office.
        foreach ($this->hierarchyIds() as $groupId) {
            $settings = $groupId === $this->id ? $this->settings : [];
            $value = data_get($settings, $key);

            if ($value === null) {
                $override = GroupSetting::withoutGlobalScopes()
                    ->where('group_id', $groupId)
                    ->where('key', $key)
                    ->first();
                $value = $override?->value;
            }

            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function onTrial(): bool
    {
        $owner = $this->billingOwner();

        return $owner->subscription_status === 'trialing'
            && $owner->trial_ends_at
            && $owner->trial_ends_at->isFuture();
    }

    private static function generateBranchCode(string $name, int $parentId): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?: 'BR');
        $base = trim($base, '-');
        $base = Str::limit($base, 30, '');

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $base.($attempt > 0 ? '-'.Str::upper(Str::random(3)) : '');

            $exists = Group::withoutGlobalScopes()
                ->where('parent_group_id', $parentId)
                ->where('branch_code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        return $base.'-'.Str::upper(Str::random(6));
    }
}
