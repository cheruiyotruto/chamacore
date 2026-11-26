<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundPayout;
use App\Services\LedgerService;
use App\Services\MerryGoRoundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerryGoRoundController extends Controller
{
    public function __construct(private readonly MerryGoRoundService $mgr)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('mgr.view');

        $cycles = MerryGoRoundCycle::query()
            ->withCount([
                'participants',
                'contributions as paid_contributions_count' => fn ($query) => $query->where('status', 'paid'),
                'contributions as pending_contributions_count' => fn ($query) => $query->whereIn('status', ['pending', 'late']),
            ])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('start_date')
            ->paginate(15)
            ->withQueryString();

        return view('mgr.index', [
            'cycles' => $cycles,
            'statuses' => ['draft', 'active', 'completed', 'cancelled'],
        ]);
    }

    public function create(): View
    {
        $this->requirePermission('mgr.manage');

        return view('mgr.create', [
            'members' => Member::query()->active()->orderBy('first_name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('mgr.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contribution_amount' => ['required', 'numeric', 'min:1'],
            'frequency' => ['required', 'in:weekly,monthly'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'rotation_type' => ['required', 'in:fixed,random'],
            'penalty_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'penalty_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'min_contributions_before_payout' => ['nullable', 'integer', 'min:0', 'max:100'],
            'payout_requires_contributions' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'member_ids' => ['required', 'array', 'min:2'],
            'member_ids.*' => ['integer'],
        ]);

        $data['contribution_amount'] = (int) round(((float) $data['contribution_amount']) * 100);
        $data['payout_requires_contributions'] = $request->boolean('payout_requires_contributions', true);
        $this->normaliseScheduleDays($data);

        try {
            $cycle = $this->mgr->createCycle($this->group(), $data, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('mgr.show', $cycle)
            ->with('status', 'Merry-go-round cycle created with '.count($data['member_ids']).' participants.');
    }

    public function edit(MerryGoRoundCycle $cycle): View
    {
        $this->authorize('manage', $cycle);

        $cycle->loadCount('participants');

        return view('mgr.edit', [
            'cycle' => $cycle,
        ]);
    }

    public function update(Request $request, MerryGoRoundCycle $cycle): RedirectResponse
    {
        $this->authorize('manage', $cycle);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contribution_amount' => ['required', 'numeric', 'min:1'],
            'frequency' => ['required', 'in:weekly,monthly'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'rotation_type' => ['required', 'in:fixed,random'],
            'penalty_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'penalty_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'min_contributions_before_payout' => ['nullable', 'integer', 'min:0', 'max:100'],
            'payout_requires_contributions' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['contribution_amount'] = (int) round(((float) $data['contribution_amount']) * 100);
        $data['payout_requires_contributions'] = $request->boolean('payout_requires_contributions', true);
        $this->normaliseScheduleDays($data);

        try {
            $cycle = $this->mgr->updateCycle($cycle, $data, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('mgr.show', $cycle)
            ->with('status', 'Cycle updated. Unpaid contributions and pending payouts were rescheduled; paid records were preserved.');
    }

    public function show(MerryGoRoundCycle $cycle, LedgerService $ledger): View
    {
        $this->authorize('view', $cycle);

        $cycle->load(['participants.member', 'payouts.member']);

        $contributions = $cycle->contributions()
            ->with('member')
            ->orderBy('due_date')
            ->orderBy('member_id')
            ->paginate(50);

        return view('mgr.show', [
            'cycle' => $cycle,
            'contributions' => $contributions,
            'poolBalance' => $ledger->accountBalance($this->group(), LedgerService::MGR_FUNDS),
            'canManage' => $this->context()->isStaff() && $this->context()->hasPermission('mgr.manage'),
        ]);
    }

    public function recordContribution(Request $request, MerryGoRoundContribution $contribution): RedirectResponse
    {
        $this->requirePermission('mgr.manage');

        $data = $request->validate([
            'method' => ['required', 'in:cash,bank,mpesa'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $this->mgr->recordContribution($contribution, $this->actor(), $data['method'], $data['reference'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Contribution recorded for '.$contribution->member?->full_name.'.');
    }

    public function payOut(Request $request, MerryGoRoundPayout $payout): RedirectResponse
    {
        $this->requirePermission('mgr.manage');

        $data = $request->validate([
            'method' => ['required', 'in:cash,bank'],
        ]);

        try {
            $this->mgr->payOut($payout, $this->actor(), $data['method']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Payout released to '.$payout->member?->full_name.'.');
    }

    public function markOverdue(MerryGoRoundCycle $cycle): RedirectResponse
    {
        $this->authorize('manage', $cycle);

        $count = $this->mgr->markOverdue($cycle);

        return back()->with('status', $count.' contribution(s) marked overdue.');
    }

    /**
     * Only the day field that matches the chosen frequency is kept.
     */
    private function normaliseScheduleDays(array &$data): void
    {
        if (($data['frequency'] ?? null) === 'weekly') {
            $data['day_of_month'] = null;
            $data['day_of_week'] = filled($data['day_of_week'] ?? null) ? (int) $data['day_of_week'] : null;
        } else {
            $data['day_of_week'] = null;
            $data['day_of_month'] = filled($data['day_of_month'] ?? null) ? (int) $data['day_of_month'] : null;
        }
    }
}
