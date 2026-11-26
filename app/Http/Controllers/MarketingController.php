<?php

namespace App\Http\Controllers;

use App\Models\MarketingLead;
use App\Models\Plan;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class MarketingController extends Controller
{
    private const MODULE_LABELS = [
        'core' => 'Members, roles and group settings',
        'savings' => 'Savings and share accounts',
        'mgr' => 'Merry-go-round cycles',
        'loans' => 'Loans, guarantees and repayments',
        'wallet' => 'Member wallets',
        'accounting' => 'Double-entry accounting and reports',
        'crm' => 'Leads and member support',
        'welfare' => 'Welfare funds and claims',
        'governance' => 'Policies and resolutions',
        'reports' => 'Financial and member statements',
        'documents' => 'Document vault and verification',
        'investments' => 'Investment products and statements',
        'meetings' => 'Meetings, attendance and resolutions',
        'single_loan' => 'One active loan at a time, based on your savings',
        'wallet_members_officials' => 'Wallet for members and group officials',
        'platform_managed' => 'Managed for you by our platform team',
        'platform_charges' => 'Transaction charges set and collected by the platform',
    ];

    public function home(): View
    {
        return view('marketing.home');
    }

    public function features(): View
    {
        return view('marketing.features');
    }

    public function pricing(): View
    {
        return view('marketing.pricing', [
            'plans' => $this->publicPlans(),
        ]);
    }

    public function about(): View
    {
        return view('marketing.about');
    }

    public function contact(): View
    {
        return view('marketing.contact', [
            'plans' => $this->publicPlans(),
        ]);
    }

    public function storeContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'organisation' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'group_type' => ['nullable', 'string', 'max:80'],
            'expected_members' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plan' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'max:0'],
        ], [
            'website.max' => 'This request could not be submitted.',
        ]);

        MarketingLead::create([
            'name' => $data['name'],
            'organisation' => $data['organisation'],
            'email' => $data['email'],
            'phone' => ($data['phone'] ?? null) ?: null,
            'group_type' => ($data['group_type'] ?? null) ?: null,
            'expected_members' => $data['expected_members'] ?? null,
            'plan' => ($data['plan'] ?? null) ?: null,
            'message' => ($data['message'] ?? null) ?: null,
            'status' => MarketingLead::STATUS_NEW,
        ]);

        return back()->with('status', 'Thank you. Your request has been received and our team will be in touch.');
    }

    /**
     * Show only plans that organisations can actually subscribe to. The
     * configured catalogue is used as a fallback before the seeder has run.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function publicPlans(): Collection
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        if ($plans->isEmpty()) {
            $plans = collect(config('chama.subscription_plans', []))
                ->map(fn (array $definition) => new Plan([
                    ...$definition,
                    'trial_days' => array_key_exists('trial_days', $definition)
                        ? (int) $definition['trial_days']
                        : (int) config('chama.trial_days', 30),
                    'is_active' => true,
                ]));
        }

        return $plans->map(function (Plan $plan): array {
            $modules = collect($plan->modules ?? [])
                ->map(fn (string $module) => $module === '*'
                    ? 'Every module, including future releases'
                    : (self::MODULE_LABELS[$module] ?? ucfirst(str_replace('_', ' ', $module))))
                ->values()
                ->all();

            return [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'price' => (int) $plan->price,
                'price_label' => Money::format((int) $plan->price).' / '.($plan->interval ?: 'monthly'),
                'interval' => $plan->interval ?: 'monthly',
                'trial_days' => (int) ($plan->trial_days ?? 0),
                'max_groups' => (int) ($plan->max_groups ?? 1),
                'max_members' => (int) ($plan->max_members ?? 0),
                'modules' => $modules,
                'featured' => $plan->slug === 'growth',
            ];
        })->values();
    }
}
