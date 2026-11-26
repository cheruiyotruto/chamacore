<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Plan;
use App\Models\SavingsAccount;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Services\PlatformAccessService;
use App\Services\PlatformRevenueService;
use App\Services\SubscriptionService;
use App\Support\GroupContext;
use App\Support\GroupResolver;
use App\Support\Money;
use App\Support\PaymentChargeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function index(Request $request): View
    {
        $context = app(GroupContext::class);

        return $context->withoutTenantScope(function () use ($request) {
            $groups = Group::query()
                ->withCount(['members', 'branches'])
                ->with(['creator:id,name,email', 'parent:id,name,branch_code', 'subscriptions' => fn ($query) => $query->latest()->limit(1)])
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = '%'.$request->string('search')->trim().'%';

                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'like', $search)
                            ->orWhere('slug', 'like', $search)
                            ->orWhere('email', 'like', $search)
                            ->orWhere('phone', 'like', $search)
                            ->orWhere('registration_no', 'like', $search);
                    });
                })
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
                ->when($request->filled('subscription_status'), fn ($query) => $query->where('subscription_status', $request->string('subscription_status')))
                ->when($request->filled('platform_mode'), function ($query) use ($request) {
                    $mode = $request->string('platform_mode')->toString();

                    if ($mode === 'managed') {
                        $query->whereIn('platform_management_mode', [
                            Group::PLATFORM_MODE_OVERSIGHT,
                            Group::PLATFORM_MODE_FULL,
                        ]);
                    } elseif ($mode === 'self') {
                        $query->where(function ($query) {
                            $query->whereNull('platform_management_mode')
                                ->orWhere('platform_management_mode', Group::PLATFORM_MODE_SELF);
                        });
                    } else {
                        $query->where('platform_management_mode', $mode);
                    }
                })
                ->orderByDesc('created_at')
                ->paginate(20)
                ->withQueryString();

            return view('platform.groups.index', [
                'groups' => $groups,
                'statuses' => ['pending', 'active', 'suspended', 'rejected'],
                'subscriptionStatuses' => ['trialing', 'active', 'past_due', 'cancelled', 'expired'],
                'platformModes' => Group::PLATFORM_MODES,
            ]);
        });
    }

    public function show(Group $group, PlatformRevenueService $revenue, LedgerService $ledger): View
    {
        $context = app(GroupContext::class);

        return $context->withoutTenantScope(function () use ($group, $revenue, $ledger) {
            $groupIds = [$group->id, ...$group->descendantIds()];

            $group->load([
                'creator:id,name,email,phone',
                'parent:id,name,branch_code',
                'platformManager:id,name,email',
                'branches' => fn ($query) => $query->withCount('members'),
                'members' => fn ($query) => $query->with('role')->orderBy('first_name')->limit(50),
                'subscriptions' => fn ($query) => $query->with('plan')->latest(),
            ]);

            return view('platform.groups.show', [
                'group' => $group,
                'stats' => [
                    'members' => Member::query()->whereIn('group_id', $groupIds)->count(),
                    'active_members' => Member::query()->whereIn('group_id', $groupIds)->where('status', 'active')->count(),
                    'savings_accounts' => SavingsAccount::query()->whereIn('group_id', $groupIds)->count(),
                    'savings_balance' => (int) SavingsAccount::query()->whereIn('group_id', $groupIds)->sum('balance'),
                    'loans' => Loan::query()->whereIn('group_id', $groupIds)->count(),
                    'loan_outstanding' => (int) Loan::query()->whereIn('group_id', $groupIds)->sum('outstanding_principal'),
                    'managed_income' => $revenue->incomeForGroup($group),
                ],
                'invoices' => SubscriptionInvoice::query()
                    ->where('group_id', $group->id)
                    ->orderByDesc('issue_date')
                    ->limit(15)
                    ->get(),
                'managementModes' => Group::PLATFORM_MODES,
                'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(),
                'chargeRules' => PaymentChargeSettings::forGroup($group),
                'chargeCategories' => PaymentChargeSettings::CATEGORIES,
                'chargeModes' => PaymentChargeSettings::MODES,
                'borneBy' => PaymentChargeSettings::BORNE_BY,
                'incomeAccounts' => $ledger->incomeAccounts($group),
                'money' => fn (?int $minor) => $minor === null ? '' : number_format($minor / 100, 2, '.', ''),
            ]);
        });
    }

    public function approve(Group $group): RedirectResponse
    {
        if ($group->status === 'active') {
            return back()->with('error', 'This group is already active.');
        }

        $old = ['status' => $group->status];
        $group->update(['status' => 'active']);

        AuditService::record('platform.group.approved', $group, $old, ['status' => 'active'], $group->id);

        return back()->with('status', $group->name.' approved and activated.');
    }

    public function suspend(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($group->status === 'suspended') {
            return back()->with('error', 'This group is already suspended.');
        }

        $old = ['status' => $group->status];
        $group->update(['status' => 'suspended']);

        AuditService::record('platform.group.suspended', $group, $old, [
            'status' => 'suspended',
            'reason' => $data['reason'] ?? null,
        ], $group->id);

        return back()->with('status', $group->name.' suspended.');
    }

    public function reactivate(Group $group): RedirectResponse
    {
        if ($group->status === 'active') {
            return back()->with('error', 'This group is already active.');
        }

        $old = ['status' => $group->status];
        $group->update(['status' => 'active']);

        AuditService::record('platform.group.reactivated', $group, $old, ['status' => 'active'], $group->id);

        return back()->with('status', $group->name.' reactivated.');
    }

    public function updateMode(Request $request, Group $group, PlatformAccessService $access): RedirectResponse
    {
        $data = $request->validate([
            'platform_management_mode' => ['required', \Illuminate\Validation\Rule::in(array_keys(Group::PLATFORM_MODES))],
        ]);

        $access->setMode($group, $data['platform_management_mode'], $this->actor());

        return back()->with('status', 'Platform management updated for '.$group->name.'.');
    }

    public function updatePublicJoin(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate([
            'public_join_enabled' => ['nullable', 'boolean'],
            'public_join_summary' => ['nullable', 'string', 'max:1000'],
        ]);

        $enabled = $request->boolean('public_join_enabled');

        if ($enabled && ! $group->isPlatformManaged()) {
            return back()->with('error', 'Enable platform oversight before allowing public applications.');
        }

        $old = $group->only(['public_join_enabled', 'public_join_summary']);

        $group->update([
            'public_join_enabled' => $enabled,
            'public_join_summary' => ($data['public_join_summary'] ?? null) ?: null,
        ]);

        AuditService::record('platform.group.public_join.updated', $group, $old, $group->only([
            'public_join_enabled', 'public_join_summary',
        ]), $group->id);

        return back()->with('status', $enabled
            ? $group->name.' is now listed on the public join page.'
            : 'Public applications are disabled for '.$group->name.'.');
    }

    public function updateCharges(Request $request, Group $group, LedgerService $ledger): RedirectResponse
    {
        if (! $group->allowsPlatformOversight()) {
            return back()->with('error', 'Enable platform oversight before managing this group\'s charges.');
        }

        $rules = PaymentChargeSettings::fromRequest(
            $request,
            array_keys($ledger->incomeAccounts($group))
        );

        \App\Models\GroupSetting::updateOrCreate(
            ['group_id' => $group->id, 'key' => PaymentChargeSettings::KEY],
            ['value' => $rules, 'updated_by' => $this->actor()->id]
        );

        AuditService::log('platform.group.payment_charges.updated', [
            'group_id' => $group->id,
            'categories' => array_keys($rules),
        ], $group);

        return back()->with('status', 'Payment charges saved for '.$group->name.'.');
    }

    public function assignPlan(
        Request $request,
        Group $group,
        SubscriptionService $subscriptions
    ): RedirectResponse {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $plan = Plan::query()->findOrFail($data['plan_id']);

        abort_unless($plan->is_active, 422, 'That plan is not active.');

        $owner = $group->billingOwner();
        $subscription = $subscriptions->subscribe($owner, $plan);

        AuditService::log('platform.group.plan.assigned', [
            'group_id' => $group->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
        ], $group);

        return back()->with('status', $plan->name.' assigned to '.$group->name.'.');
    }

    public function enter(Group $group, PlatformAccessService $access): RedirectResponse
    {
        abort_unless($group->allowsFullPlatformControl(), 403, 'Full control is not enabled for this group.');
        abort_unless($group->status === 'active', 403, 'Only active groups can be opened.');

        $access->ensureFullControlMembership($group, $this->actor());

        session([
            GroupResolver::SESSION_KEY => $group->id,
            GroupResolver::FULL_CONTROL_KEY => $group->id,
        ]);

        return redirect()->route('dashboard')
            ->with('status', 'Opened '.$group->name.' with full platform control.');
    }

    public function leave(): RedirectResponse
    {
        session()->forget([
            GroupResolver::SESSION_KEY,
            GroupResolver::FULL_CONTROL_KEY,
        ]);

        return redirect()->route('platform.dashboard')
            ->with('status', 'Returned to the platform console.');
    }
}
