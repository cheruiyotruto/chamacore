<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\TellerEntry;
use App\Models\TransactionCharge;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Support\Appearance;
use App\Support\CurrencySettings;
use App\Support\EmployerFinance;
use App\Support\GuaranteeSettings;
use App\Support\JoinRequirements;
use App\Support\MgrSavingsSettings;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use App\Support\PlanAccess;
use App\Support\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Group-level settings that are editable from the UI. Financial values are
     * stored in minor units and converted by the form layer.
     */
    private const SETTING_KEYS = [
        'membership_fee',
        'meeting_absent_fine',
        'meeting_late_fine',
        'loan_late_penalty_bps',
        'min_savings_for_loan',
        'require_mfa_for_staff',
        'allow_member_wallet_topup',
        'allow_member_on_behalf_payments',
        'allow_member_float_purchases',
        'receipt_footer',
        'theme_border_color',
        'theme_accent_color',
        'theme_background_color',
        'join_requirements',
        'employer_finance',
    ];

    public function index(): View
    {
        $this->requirePermission('settings.view');
        $group = $this->group();

        $stored = GroupSetting::where('key', 'like', '%')
            ->get()
            ->mapWithKeys(fn (GroupSetting $setting) => [$setting->key => $setting->value]);

        return view('settings.index', [
            'group' => $group,
            'settings' => $stored,
            'settingKeys' => self::SETTING_KEYS,
            'roles' => Role::orderBy('name')->get(),
            'templates' => NotificationTemplate::whereNull('group_id')
                ->orWhere('group_id', $group->id)
                ->orderBy('key')
                ->get(),
            'permissions' => $this->permissionCatalogue(),
            'joinRequirements' => JoinRequirements::for($group)->toArray(),
            'employerFinance' => EmployerFinance::for($group)->toArray(),
            'guaranteeSettings' => GuaranteeSettings::for($group)->toArray(),
            'guaranteeModes' => GuaranteeSettings::MODES,
            'guaranteeChannels' => GuaranteeSettings::CHANNEL_LABELS,
            'mgrSavings' => MgrSavingsSettings::for($group)->toArray(),
            'mgrSavingsModes' => MgrSavingsSettings::MODES,
            'mgrSavingsBases' => MgrSavingsSettings::BASES,
            'members' => Member::active()->orderBy('first_name')->get(),
            'charges' => TransactionCharge::query()
                ->with('members')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'chargeCategories' => ['*' => 'All teller transactions'] + collect(TellerEntry::CATEGORIES)
                ->mapWithKeys(fn (array $category, string $key) => [$key => $category['label']])
                ->all(),
            'membershipCategories' => $this->membershipCategories($group),
            'incomeAccounts' => app(LedgerService::class)->incomeAccounts($group),
            'paymentChargeRules' => PaymentChargeSettings::forGroup($group),
            'paymentChargeOverride' => PaymentChargeSettings::groupOverride($group),
            'paymentChargeCategories' => PaymentChargeSettings::CATEGORIES,
            'paymentChargeModes' => PaymentChargeSettings::MODES,
            'paymentChargeBorneBy' => PaymentChargeSettings::BORNE_BY,
            'currencyOptions' => CurrencySettings::options($group->currency),
            'portalAccessSections' => PortalAccess::SECTIONS,
            'portalDocumentsEnabled' => PortalAccess::memberDocumentsEnabled($group),
        ]);
    }

    public function updateGroup(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $group = $this->group();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'registration_no' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'county' => ['nullable', 'string', 'max:80'],
            'sub_county' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'string', 'size:3', Rule::in(CurrencySettings::selectableCodes($group->currency))],
            'timezone' => ['required', 'string', 'max:60'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'currency.in' => 'Select a currency enabled by the platform administrator.',
        ]);

        $old = $group->only(array_keys($data));
        $group->update($data);

        AuditService::record('group.updated', $group, $old, $group->only(array_keys($data)));

        return back()->with('status', 'Group profile updated.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable'],
            'membership_fee' => ['nullable', 'numeric', 'min:0'],
            'meeting_absent_fine' => ['nullable', 'numeric', 'min:0'],
            'meeting_late_fine' => ['nullable', 'numeric', 'min:0'],
            'loan_late_penalty_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'min_savings_for_loan' => ['nullable', 'numeric', 'min:0'],
            'require_mfa_for_staff' => ['nullable', 'boolean'],
            'allow_member_wallet_topup' => ['nullable', 'boolean'],
            'allow_member_on_behalf_payments' => ['nullable', 'boolean'],
            'allow_member_float_purchases' => ['nullable', 'boolean'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'theme_border_color' => ['nullable', Rule::in(array_keys(Appearance::BORDERS))],
            'theme_accent_color' => ['nullable', Rule::in(array_keys(Appearance::ACCENTS))],
            'theme_background_color' => ['nullable', Rule::in(array_keys(Appearance::BACKGROUNDS))],
        ]);

        $money = ['membership_fee', 'meeting_absent_fine', 'meeting_late_fine', 'min_savings_for_loan'];

        foreach (self::SETTING_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (in_array($key, $money, true)) {
                $value = $value === null ? null : (int) round(((float) $value) * 100);
            }

            if (in_array($key, ['require_mfa_for_staff', 'allow_member_wallet_topup', 'allow_member_on_behalf_payments', 'allow_member_float_purchases'], true)) {
                $value = (bool) $value;
            }

            GroupSetting::updateOrCreate(
                ['group_id' => $this->group()->id, 'key' => $key],
                ['value' => $value, 'updated_by' => $this->actor()->id]
            );
        }

        AuditService::log('group.settings.updated', ['keys' => self::SETTING_KEYS], $this->group());

        return back()->with('status', 'Group settings saved.');
    }

    public function updateEmployerFinance(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'office_name' => ['nullable', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'remit_account_name' => ['nullable', 'string', 'max:150'],
            'remit_account_number' => ['nullable', 'string', 'max:60'],
            'remit_paybill' => ['nullable', 'string', 'max:60'],
            'remit_bank_name' => ['nullable', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $settings = EmployerFinance::normalize($data);

        GroupSetting::updateOrCreate(
            ['group_id' => $this->group()->id, 'key' => EmployerFinance::SETTING_KEY],
            ['value' => $settings, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('group.employer_finance.updated', [
            'enabled' => $settings['enabled'],
            'member_ids' => $settings['member_ids'],
            'office_name' => $settings['office_name'],
        ], $this->group());

        return back()->with('status', 'Employer finance loan settings saved.');
    }

    public function updateGuarantees(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'new_guarantees' => ['required', Rule::in(array_keys(GuaranteeSettings::MODES))],
            'existing_commitments' => ['required', Rule::in(array_keys(GuaranteeSettings::MODES))],
            'require_signature' => ['sometimes', 'boolean'],
            'notification_channels' => ['sometimes', 'array'],
        ]);

        $data['require_signature'] = $request->boolean('require_signature');
        // A hidden empty input represents "no channels selected" when every
        // checkbox is unticked, so strip it before normalising.
        $data['notification_channels'] = array_values(array_filter(
            $data['notification_channels'] ?? [],
            fn ($channel) => is_string($channel) && $channel !== ''
        ));
        $settings = GuaranteeSettings::normalize($data);

        GroupSetting::updateOrCreate(
            ['group_id' => $this->group()->id, 'key' => GuaranteeSettings::SETTING_KEY],
            ['value' => $settings, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('group.guarantees.updated', $settings, $this->group());

        return back()->with('status', 'Guarantee cover settings saved.');
    }

    public function updateMgrSavings(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'mode' => ['nullable', Rule::in(array_keys(MgrSavingsSettings::MODES))],
            'basis' => ['nullable', Rule::in(array_keys(MgrSavingsSettings::BASES))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $settings = MgrSavingsSettings::normalize([
            'enabled' => $request->boolean('enabled'),
            'mode' => $data['mode'] ?? null,
            'basis' => $data['basis'] ?? null,
            'amount' => Money::toMinor($data['amount'] ?? 0),
            'rate_bps' => (int) ($data['rate_bps'] ?? 0),
        ]);

        GroupSetting::updateOrCreate(
            ['group_id' => $this->group()->id, 'key' => MgrSavingsSettings::SETTING_KEY],
            ['value' => $settings, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('group.mgr_savings.updated', $settings, $this->group());

        return back()->with('status', 'MGR savings transfer settings saved.');
    }

    public function updateJoinRequirements(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $request->validate([
            'requirements' => ['nullable', 'array'],
            'requirements.joining_details' => ['nullable', 'string', 'max:'.JoinRequirements::JOINING_DETAILS_MAX],
        ]);

        $requirements = JoinRequirements::normalize((array) $request->input('requirements', []));

        GroupSetting::updateOrCreate(
            ['group_id' => $this->group()->id, 'key' => JoinRequirements::SETTING_KEY],
            ['value' => $requirements, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('group.join_requirements.updated', ['requirements' => $requirements], $this->group());

        return back()->with('status', 'Joining form requirements saved.');
    }

    public function updatePortalAccess(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $group = $this->group();

        $data = $request->validate([
            'member_documents_enabled' => ['nullable', 'boolean'],
            'access' => ['nullable', 'array'],
            'access.*' => ['nullable', 'array'],
            'access.*.enabled' => ['nullable', 'array'],
            'access.*.enabled.*' => ['string'],
        ]);

        $members = Member::query()->get();
        $validSections = PortalAccess::sectionKeys();

        foreach ($members as $member) {
            $config = $data['access'][$member->id] ?? null;
            if ($config === null) {
                continue;
            }

            $enabled = collect($config['enabled'] ?? [])
                ->filter(fn ($key) => is_string($key) && in_array($key, $validSections, true))
                ->unique()
                ->values()
                ->all();

            // If all sections are enabled, store null (default = all access).
            $disabled = array_values(array_diff($validSections, $enabled));

            $member->portal_sections = $disabled === [] ? null : ['disabled' => $disabled];
            $member->save();
        }

        $memberDocumentsEnabled = (bool) $request->boolean('member_documents_enabled');
        GroupSetting::updateOrCreate(
            ['group_id' => $group->id, 'key' => PortalAccess::MEMBER_DOCUMENTS_SETTING],
            ['value' => $memberDocumentsEnabled, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('group.portal_access.updated', [
            'members' => array_keys($data['access'] ?? []),
            'member_documents_enabled' => $memberDocumentsEnabled,
        ], $this->group());

        return back()->with('status', 'Member portal access updated.');
    }

    private function requireChargeManagement(): void
    {
        abort_if(
            PlanAccess::isPlatformManaged($this->group()),
            403,
            'Transaction charges are set by the platform administrator on this plan.'
        );
    }

    public function storeCharge(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');
        $this->requireChargeManagement();

        $data = $this->validatedCharge($request);

        $charge = TransactionCharge::create($data['attributes'] + [
            'group_id' => $this->group()->id,
            'created_by' => $this->actor()->id,
            'updated_by' => $this->actor()->id,
        ]);

        $this->syncChargeMembers($charge, $data['member_ids']);

        AuditService::log('group.charge.created', [
            'charge_id' => $charge->id,
            'name' => $charge->name,
            'category' => $charge->category,
            'basis' => $charge->basis,
            'amount' => $charge->amount,
            'rate_bps' => $charge->rate_bps,
            'applies_to' => $charge->applies_to,
        ], $this->group());

        return back()->with('status', 'Transaction charge created.');
    }

    public function updateCharge(Request $request, TransactionCharge $charge): RedirectResponse
    {
        $this->requirePermission('settings.manage');
        $this->requireChargeManagement();

        $data = $this->validatedCharge($request);
        $watch = [
            'name', 'category', 'basis', 'amount', 'rate_bps', 'min_amount', 'max_amount',
            'is_mandatory', 'is_active', 'applies_to', 'member_categories',
            'income_account_code', 'description', 'sort_order',
        ];
        $old = $charge->only($watch);

        $charge->update($data['attributes'] + ['updated_by' => $this->actor()->id]);
        $this->syncChargeMembers($charge, $data['member_ids']);

        AuditService::record('group.charge.updated', $charge, $old, $charge->only($watch));

        return back()->with('status', 'Transaction charge updated.');
    }

    public function destroyCharge(TransactionCharge $charge): RedirectResponse
    {
        $this->requirePermission('settings.manage');
        $this->requireChargeManagement();

        $charge->delete();

        AuditService::log('group.charge.deleted', [
            'charge_id' => $charge->id,
            'name' => $charge->name,
        ], $this->group());

        return back()->with('status', 'Transaction charge deleted.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'alpha_dash'],
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'approval_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (Role::where('key', $data['key'])->exists()) {
            return back()->withInput()->with('error', 'A role with this key already exists.');
        }

        $role = Role::create([
            'group_id' => $this->group()->id,
            'key' => $data['key'],
            'name' => $data['name'],
            'permissions' => array_values($data['permissions'] ?? []),
            'approval_limit' => isset($data['approval_limit']) ? (int) round(((float) $data['approval_limit']) * 100) : null,
            'is_system' => false,
        ]);

        AuditService::record('role.created', $role, null, $role->only(['key', 'name', 'permissions']));

        return back()->with('status', 'Role created.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'approval_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($role->key === 'member') {
            return back()->with('error', 'The member role cannot be modified.');
        }

        $old = $role->only(['name', 'permissions', 'approval_limit']);

        $role->update([
            'name' => $data['name'],
            'permissions' => array_values($data['permissions'] ?? []),
            'approval_limit' => isset($data['approval_limit']) ? (int) round(((float) $data['approval_limit']) * 100) : null,
        ]);

        AuditService::record('role.updated', $role, $old, $role->only(['name', 'permissions', 'approval_limit']));

        return back()->with('status', 'Role updated.');
    }

    public function updateTemplate(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update([
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditService::record('notification_template.updated', $template, null, ['key' => $template->key]);

        return back()->with('status', 'Template '.$template->key.' updated.');
    }

    /**
     * @return array{attributes: array<string, mixed>, member_ids: array<int, int>}
     */
    private function validatedCharge(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', Rule::in(array_merge(
                [TransactionCharge::ALL_CATEGORIES],
                array_keys(TellerEntry::CATEGORIES)
            ))],
            'basis' => ['required', Rule::in([TransactionCharge::BASIS_FIXED, TransactionCharge::BASIS_PERCENT])],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'is_mandatory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'applies_to' => ['required', Rule::in([
                TransactionCharge::APPLIES_ALL,
                TransactionCharge::APPLIES_CATEGORIES,
                TransactionCharge::APPLIES_MEMBERS,
            ])],
            'member_categories' => ['nullable', 'array'],
            'member_categories.*' => ['string', 'max:40'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'income_account_code' => ['required', 'string', 'max:20', Rule::in(array_keys(app(LedgerService::class)->incomeAccounts($this->group())))],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $basis = $data['basis'];
        $fixed = Money::toMinor($data['amount'] ?? 0);
        $rateBps = (int) round(((float) ($data['rate'] ?? 0)) * 100);
        $min = Money::toMinor($data['min_amount'] ?? 0);
        $max = isset($data['max_amount']) && $data['max_amount'] !== null && $data['max_amount'] !== ''
            ? Money::toMinor($data['max_amount'])
            : null;

        if ($max !== null && $max < $min) {
            $max = $min;
        }

        $appliesTo = $data['applies_to'];
        $memberIds = $appliesTo === TransactionCharge::APPLIES_MEMBERS
            ? array_values(array_map('intval', $data['member_ids'] ?? []))
            : [];
        $memberCategories = $appliesTo === TransactionCharge::APPLIES_CATEGORIES
            ? array_values(array_unique(array_map('strval', $data['member_categories'] ?? [])))
            : [];

        if ($appliesTo === TransactionCharge::APPLIES_CATEGORIES && $memberCategories === []) {
            throw ValidationException::withMessages([
                'member_categories' => 'Tick at least one membership category, or choose a different audience.',
            ]);
        }

        if ($appliesTo === TransactionCharge::APPLIES_MEMBERS && $memberIds === []) {
            throw ValidationException::withMessages([
                'member_ids' => 'Tick at least one member, or choose a different audience.',
            ]);
        }

        return [
            'attributes' => [
                'name' => $data['name'],
                'category' => $data['category'],
                'basis' => $basis,
                'amount' => $basis === TransactionCharge::BASIS_FIXED ? $fixed : 0,
                'rate_bps' => $basis === TransactionCharge::BASIS_PERCENT ? $rateBps : 0,
                'min_amount' => $min,
                'max_amount' => $max,
                'is_mandatory' => $request->boolean('is_mandatory'),
                'is_active' => $request->boolean('is_active'),
                'applies_to' => $appliesTo,
                'member_categories' => $memberCategories,
                'income_account_code' => $data['income_account_code'],
                'description' => $data['description'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ],
            'member_ids' => $memberIds,
        ];
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    private function syncChargeMembers(TransactionCharge $charge, array $memberIds): void
    {
        if ($charge->applies_to !== TransactionCharge::APPLIES_MEMBERS) {
            $charge->members()->sync([]);

            return;
        }

        $ids = Member::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->whereIn('id', $memberIds)
            ->pluck('id')
            ->all();

        $charge->members()->sync($ids);
    }

    /**
     * @return array<int, string>
     */
    private function membershipCategories(Group $group): array
    {
        $stored = Member::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->whereNotNull('membership_category')
            ->distinct()
            ->pluck('membership_category')
            ->filter()
            ->values()
            ->all();

        return array_values(array_unique(array_merge(['ordinary', 'associate', 'honorary'], $stored)));
    }

    /**
     * @return array<int, string>
     */
    private function permissionCatalogue(): array
    {
        return [
            'dashboard.view', 'members.view', 'members.create', 'members.export',
            'savings.view', 'savings.manage', 'loans.view', 'loans.create',
            'investments.view', 'investments.deposit', 'investments.manage',
            'loans.approve', 'loans.disburse', 'loans.restructure', 'loans.write_off',
            'mgr.view', 'mgr.manage', 'meetings.view', 'meetings.manage',
            'wallet.view', 'wallet.adjust', 'wallet.transfer', 'wallet.reconcile',
            'payments.view', 'payments.initiate', 'bills.view', 'bills.manage',
            'accounting.view', 'accounting.journal',
            'accounting.manage', 'reports.view', 'reports.export', 'documents.view',
            'documents.manage', 'crm.view', 'crm.manage', 'governance.view',
            'governance.manage', 'welfare.view', 'welfare.manage', 'settings.view',
            'settings.manage', 'subscription.view', 'subscription.manage',
            'notifications.view', 'notifications.manage',
        ];
    }
}
