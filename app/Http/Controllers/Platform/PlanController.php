<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('platform.plans.index', [
            'plans' => Plan::query()
                ->withCount(['subscriptions' => fn ($query) => $query->whereIn('status', ['active', 'trialing'])])
                ->orderBy('price')
                ->get(),
            'intervals' => ['monthly', 'quarterly', 'annual'],
            'modules' => [
                'core', 'savings', 'mgr', 'loans', 'wallet', 'accounting', 'crm',
                'investments', 'welfare', 'governance', 'reports', 'documents', 'meetings',
                'single_loan', 'wallet_members_officials', 'platform_managed', 'platform_charges',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $plan = Plan::create([
            ...$data,
            'price' => (int) round(((float) $data['price']) * 100),
            'modules' => $this->modules($request),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditService::record('platform.plan.created', $plan, null, $plan->only(['name', 'slug', 'price', 'interval']));

        return back()->with('status', 'Plan '.$plan->name.' created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validated($request, $plan);

        $old = $plan->only(['name', 'slug', 'price', 'interval', 'trial_days', 'max_groups', 'max_members', 'is_active']);

        $plan->update([
            ...$data,
            'price' => (int) round(((float) $data['price']) * 100),
            'modules' => $this->modules($request),
            'is_active' => $request->boolean('is_active', $plan->is_active),
        ]);

        AuditService::record('platform.plan.updated', $plan, $old, $plan->only(['name', 'slug', 'price', 'interval', 'is_active']));

        return back()->with('status', 'Plan '.$plan->name.' updated.');
    }

    public function toggle(Plan $plan): RedirectResponse
    {
        if ($plan->is_active && Subscription::query()->withoutGlobalScopes()
            ->where('plan_id', $plan->id)
            ->whereIn('status', ['active', 'trialing'])
            ->exists()) {
            $replacement = Plan::query()
                ->where('id', '!=', $plan->id)
                ->where('is_active', true)
                ->exists();

            if (! $replacement) {
                return back()->with('error', 'At least one active plan must remain available.');
            }
        }

        $old = ['is_active' => $plan->is_active];
        $plan->update(['is_active' => ! $plan->is_active]);

        AuditService::record('platform.plan.toggled', $plan, $old, ['is_active' => $plan->is_active]);

        return back()->with('status', 'Plan '.$plan->name.' is now '.($plan->is_active ? 'active' : 'inactive').'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable', 'string', 'max:120', 'alpha_dash',
                Rule::unique('plans', 'slug')->ignore($plan?->id),
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'interval' => ['required', 'in:monthly,quarterly,annual'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_groups' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_members' => ['required', 'integer', 'min:1', 'max:1000000'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);

        return $data;
    }

    /**
     * @return array<int, string>
     */
    private function modules(Request $request): array
    {
        $modules = array_values(array_unique($request->input('modules', [])));

        return $modules ?: ['core'];
    }
}
