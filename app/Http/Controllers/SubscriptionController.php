<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionService;
use App\Support\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function show(): View
    {
        $this->requirePermission('subscription.view');
        $group = $this->group();
        $owner = $group->billingOwner();

        return view('subscription.show', [
            'group' => $group,
            'subscription' => $owner->activeSubscription(),
            'invoices' => SubscriptionInvoice::query()
                ->where('group_id', $owner->id)
                ->orderByDesc('issue_date')
                ->paginate(15),
            'plans' => Plan::where('is_active', true)
                ->orderBy('price')
                ->get()
                ->reject(fn (Plan $plan) => PlanAccess::isPlatformManagedPlan($plan))
                ->values(),
            'canManage' => $group->isHeadOffice() && $this->context()->hasPermission('subscription.manage'),
        ]);
    }

    public function subscribe(Request $request, Plan $plan): RedirectResponse
    {
        $this->requirePermission('subscription.manage');

        if ($this->group()->isBranch()) {
            return back()->with('error', 'Subscriptions are managed by the head office organisation.');
        }

        abort_if(
            PlanAccess::isPlatformManagedPlan($plan),
            403,
            'This plan is assigned by the platform administrator.'
        );

        try {
            $subscription = $this->subscriptions->subscribe($this->group(), $plan);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('subscription.show')
            ->with('status', 'Subscribed to '.$plan->name.'. Invoice '.$subscription->invoices()->latest()->first()?->invoice_no.' issued.');
    }

    public function cancel(Subscription $subscription): RedirectResponse
    {
        $this->requirePermission('subscription.manage');

        abort_if($this->group()->isBranch(), 403, 'Subscriptions are managed by the head office organisation.');
        abort_unless($subscription->group_id === $this->group()->id, 403);

        $this->subscriptions->cancel($subscription, $this->actor());

        return back()->with('status', 'Subscription cancelled. Access continues until the end of the current period.');
    }

    public function payInvoice(Request $request, SubscriptionInvoice $invoice): RedirectResponse
    {
        $this->requirePermission('subscription.manage');

        abort_if($this->group()->isBranch(), 403, 'Subscriptions are managed by the head office organisation.');
        abort_unless($invoice->group_id === $this->group()->id, 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank,mpesa,card'],
            'reference' => ['required', 'string', 'max:100'],
        ]);

        try {
            $this->subscriptions->recordPayment(
                $invoice,
                (int) round(((float) $data['amount']) * 100),
                $data['method'],
                $data['reference'],
                $this->actor()
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Subscription invoice settled.');
    }
}
