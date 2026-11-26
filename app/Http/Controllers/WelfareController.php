<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Member;
use App\Models\WelfareClaim;
use App\Models\WelfareContribution;
use App\Models\WelfareFund;
use App\Services\AuditService;
use App\Services\WelfareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WelfareController extends Controller
{
    public function __construct(private readonly WelfareService $welfare)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('welfare.view');

        $funds = WelfareFund::query()
            ->withCount(['contributions', 'claims'])
            ->orderBy('name')
            ->get();

        $claims = WelfareClaim::query()
            ->with(['fund', 'member'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(25, ['*'], 'claims')
            ->withQueryString();

        return view('welfare.index', [
            'funds' => $funds,
            'claims' => $claims,
            'members' => Member::query()->active()->orderBy('first_name')->get(),
            'statuses' => ['pending', 'approved', 'rejected', 'disbursed'],
            'canManage' => $this->context()->hasPermission('welfare.manage'),
        ]);
    }

    public function storeFund(Request $request): RedirectResponse
    {
        $this->requirePermission('welfare.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contribution_amount' => ['nullable', 'numeric', 'min:0'],
            'contribution_frequency' => ['nullable', 'in:weekly,monthly,none'],
        ]);

        $fund = WelfareFund::create([
            'group_id' => $this->group()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'balance' => 0,
            'contribution_amount' => isset($data['contribution_amount']) ? (int) round(((float) $data['contribution_amount']) * 100) : null,
            'contribution_frequency' => $data['contribution_frequency'] ?? null,
            'is_active' => true,
        ]);

        AuditService::record('welfare.fund.created', $fund, null, $fund->only(['name', 'contribution_amount']));

        return back()->with('status', 'Welfare fund created.');
    }

    public function contribute(Request $request, WelfareFund $fund): RedirectResponse
    {
        $this->requirePermission('welfare.manage');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank,mpesa'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $member = Member::query()->findOrFail($data['member_id']);

        try {
            $this->welfare->contribute(
                $fund,
                $member,
                (int) round(((float) $data['amount']) * 100),
                $this->actor(),
                $data['method'],
                $data['reference'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Welfare contribution recorded.');
    }

    public function requestClaim(Request $request, WelfareFund $fund): RedirectResponse
    {
        $this->requirePermission('welfare.manage');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'type' => ['required', 'in:funeral,medical,emergency,other'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $member = Member::query()->findOrFail($data['member_id']);

        try {
            $this->welfare->requestClaim($fund, $member, [
                'type' => $data['type'],
                'amount' => (int) round(((float) $data['amount']) * 100),
                'reason' => $data['reason'],
            ]);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Welfare claim submitted for approval.');
    }

    public function approveClaim(Request $request, WelfareClaim $claim): RedirectResponse
    {
        $this->requirePermission('welfare.manage');

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->welfare->approve($claim, $this->actor(), $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Welfare claim approved.');
    }

    public function rejectClaim(Request $request, WelfareClaim $claim): RedirectResponse
    {
        $this->requirePermission('welfare.manage');

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->welfare->reject($claim, $this->actor(), $data['reason']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Welfare claim rejected.');
    }

    public function disburseClaim(Request $request, WelfareClaim $claim): RedirectResponse
    {
        $this->requirePermission('welfare.manage');

        $data = $request->validate(['method' => ['required', 'in:cash,bank']]);

        try {
            $this->welfare->disburse($claim, $this->actor(), $data['method']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Welfare claim disbursed.');
    }
}
