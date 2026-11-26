<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\Member;
use App\Services\InvestmentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvestmentController extends Controller
{
    public function index(): View
    {
        $this->requirePermission('investments.view');

        $open = Investment::with(['member', 'product'])
            ->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_MATURED])
            ->orderBy('maturity_date')
            ->get();

        $service = app(InvestmentService::class);

        foreach ($open as $investment) {
            $service->accrueDueInterest($investment);
        }

        return view('investments.index', [
            'investments' => Investment::with(['member', 'product'])
                ->orderByDesc('created_at')
                ->paginate(20),
            'products' => InvestmentProduct::withCount('investments')->orderBy('name')->get(),
            'members' => Member::query()->active()->orderBy('first_name')->get(),
            'principalTotal' => (int) Investment::whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_MATURED])->sum('principal'),
            'interestTotal' => (int) Investment::whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_MATURED])->sum('accrued_interest'),
            'maturedCount' => Investment::where('status', Investment::STATUS_MATURED)->count(),
            'canManage' => $this->context()->hasPermission('investments.manage'),
            'canDeposit' => $this->context()->hasPermission('investments.deposit'),
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $this->requirePermission('investments.manage');

        $data = $this->validateProduct($request);
        $code = Str::upper($data['code']);

        if (InvestmentProduct::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'An investment product with this code already exists.']);
        }

        InvestmentProduct::create($this->productAttributes($request, $data, $code));

        return back()->with('status', 'Investment product created.');
    }

    public function updateProduct(Request $request, InvestmentProduct $product): RedirectResponse
    {
        $this->requirePermission('investments.manage');

        $data = $this->validateProduct($request);
        $code = Str::upper($data['code']);

        $duplicate = InvestmentProduct::where('code', $code)->whereKeyNot($product->id)->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => 'An investment product with this code already exists.']);
        }

        $product->update($this->productAttributes($request, $data, $code));

        return back()->with('status', 'Investment product updated.');
    }

    public function toggleProduct(InvestmentProduct $product): RedirectResponse
    {
        $this->requirePermission('investments.manage');

        $product->update(['is_active' => ! $product->is_active]);

        return back()->with('status', 'Investment product '.($product->is_active ? 'opened' : 'closed').'.');
    }

    public function place(Request $request, InvestmentService $service): RedirectResponse
    {
        $this->authorize('deposit', Investment::class);

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'investment_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['nullable', 'date'],
            'funding_source' => ['required', Rule::in(['cash', 'bank', 'wallet'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $member = Member::query()->where('status', 'active')->findOrFail($data['member_id']);
        $product = InvestmentProduct::query()->where('is_active', true)->findOrFail($data['investment_product_id']);

        try {
            $investment = $service->open(
                member: $member,
                product: $product,
                amount: Money::toMinor($data['amount']),
                actor: $this->actor(),
                startDate: $data['start_date'] ?? null,
                fundingSource: $data['funding_source'],
                reference: $data['reference'] ?? null,
                notes: $data['notes'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('investments.show', $investment)
            ->with('status', 'Investment recorded for '.$member->full_name.'.');
    }

    public function show(Investment $investment, InvestmentService $service): View
    {
        $this->authorize('view', $investment);

        $service->accrueDueInterest($investment);
        $investment->refresh()->load(['member', 'product', 'schedules', 'transactions']);

        return view('investments.show', [
            'investment' => $investment,
            'canManage' => $this->context()->hasPermission('investments.manage'),
            'canWithdraw' => $investment->isOpen()
                && ($investment->isMatured() || (bool) $investment->product?->early_withdrawal_allowed
                    || $this->context()->hasPermission('investments.manage')),
        ]);
    }

    public function accrue(Investment $investment, InvestmentService $service): RedirectResponse
    {
        $this->authorize('manage', Investment::class);

        $amount = $service->accrueDueInterest($investment);

        return back()->with(
            'status',
            $amount > 0
                ? 'Interest of '.Money::format($amount).' accrued.'
                : 'No interest period is due yet.'
        );
    }

    public function settle(Request $request, Investment $investment, InvestmentService $service): RedirectResponse
    {
        $this->authorize('manage', Investment::class);

        $data = $request->validate([
            'destination' => ['required', Rule::in(['cash', 'bank', 'wallet'])],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $transaction = $service->withdraw(
                investment: $investment,
                destination: $data['destination'],
                actor: $this->actor(),
                force: $request->boolean('force')
            );
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Investment settled. '.Money::format($transaction->amount).' paid out.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProduct(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'type' => ['required', Rule::in(array_keys(InvestmentProduct::TYPES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['required', Rule::in(array_keys(InvestmentProduct::PERIODS))],
            'interest_method' => ['required', Rule::in(array_keys(InvestmentProduct::METHODS))],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'early_withdrawal_allowed' => ['nullable', 'boolean'],
            'early_withdrawal_penalty' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function productAttributes(Request $request, array $data, string $code): array
    {
        return [
            'name' => $data['name'],
            'code' => $code,
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'interest_rate_bps' => (int) round(((float) $data['interest_rate']) * 100),
            'interest_period' => $data['interest_period'],
            'interest_method' => $data['interest_method'],
            'duration_months' => (int) $data['duration_months'],
            'min_amount' => Money::toMinor($data['min_amount'] ?? 0),
            'max_amount' => isset($data['max_amount']) && $data['max_amount'] !== ''
                ? Money::toMinor($data['max_amount'])
                : null,
            'early_withdrawal_allowed' => $request->boolean('early_withdrawal_allowed'),
            'early_withdrawal_penalty_bps' => (int) round(((float) ($data['early_withdrawal_penalty'] ?? 0)) * 100),
            'terms' => $data['terms'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
