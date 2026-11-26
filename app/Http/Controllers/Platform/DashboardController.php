<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlatformRevenueService;
use App\Support\GroupContext;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(PlatformRevenueService $revenue): View
    {
        $context = app(GroupContext::class);

        return $context->withoutTenantScope(function () use ($revenue) {
            $platformIncome = $revenue->summary();
            $activeSubscriptions = Subscription::query()
                ->with('plan')
                ->whereIn('status', ['active', 'trialing'])
                ->get();

            $mrr = $activeSubscriptions->sum(function (Subscription $subscription) {
                $price = (int) ($subscription->plan?->price ?? 0);

                return match ($subscription->plan?->interval) {
                    'quarterly' => intdiv($price, 3),
                    'annual' => intdiv($price, 12),
                    default => $price,
                };
            });

            return view('platform.dashboard', [
                'stats' => [
                    'groups' => Group::query()->where('is_branch', false)->count(),
                    'branches' => Group::query()->where('is_branch', true)->count(),
                    'pending_groups' => Group::query()->where('is_branch', false)->where('status', 'pending')->count(),
                    'active_groups' => Group::query()->where('is_branch', false)->where('status', 'active')->count(),
                    'suspended_groups' => Group::query()->where('is_branch', false)->where('status', 'suspended')->count(),
                    'members' => Member::query()->where('status', 'active')->count(),
                    'active_subscriptions' => $activeSubscriptions->count(),
                    'mrr' => (int) $mrr,
                    'managed_income' => $platformIncome['groupIncome'],
                    'platform_income' => $platformIncome['totalIncome'],
                    'managed_groups' => $platformIncome['groups']->count(),
                    'plans' => Plan::query()->where('is_active', true)->count(),
                ],
                'recentGroups' => Group::query()
                    ->withCount('members')
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(),
                'expiringSubscriptions' => Subscription::query()
                    ->with(['group', 'plan'])
                    ->whereIn('status', ['active', 'trialing'])
                    ->whereNotNull('ends_at')
                    ->whereBetween('ends_at', [now(), now()->addDays(30)])
                    ->orderBy('ends_at')
                    ->limit(8)
                    ->get(),
                'planBreakdown' => Subscription::query()
                    ->select('plan_id', DB::raw('count(*) as subscriptions_count'))
                    ->whereIn('status', ['active', 'trialing'])
                    ->groupBy('plan_id')
                    ->with('plan')
                    ->get(),
            ]);
        });
    }
}
