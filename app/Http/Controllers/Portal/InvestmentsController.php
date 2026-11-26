<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\InvestmentTransaction;
use App\Models\Wallet;
use App\Services\InvestmentService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvestmentsController extends Controller
{
    public function __construct(private readonly InvestmentService $investments)
    {
    }

    public function index(): View
    {
        $this->authorize('viewAny', Investment::class);

        $member = $this->membership();
        $this->investments->processDueForMember($member);

        $investments = Investment::query()
            ->with('product')
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->get();

        return view('portal.investments.index', [
            'investments' => $investments,
            'products' => InvestmentProduct::query()
                ->where('is_active', true)
                ->orderBy('min_amount')
                ->orderBy('name')
                ->get(),
            'summary' => $this->investments->summaryFor($member),
            'recent' => InvestmentTransaction::query()
                ->with('investment.product')
                ->where('member_id', $member->id)
                ->latest('transaction_date')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Investment::class);

        return view('portal.investments.create', [
            'products' => InvestmentProduct::query()
                ->where('is_active', true)
                ->orderBy('min_amount')
                ->orderBy('name')
                ->get(),
            'wallet' => Wallet::query()->where('member_id', $this->membership()->id)->first(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->authorize('create', Investment::class);

        $data = $request->validate([
            'investment_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['nullable', 'date'],
        ]);

        $product = InvestmentProduct::query()
            ->where('is_active', true)
            ->findOrFail($data['investment_product_id']);

        try {
            $quote = $this->investments->quote(
                $product,
                Money::toMinor($data['amount']),
                $data['start_date'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'expected_interest' => $quote['expected_interest'],
            'expected_total' => $quote['expected_total'],
            'maturity_date' => $quote['maturity_date'],
            'period_count' => $quote['period_count'],
            'schedule' => collect($quote['schedule'])->map(fn (array $period) => [
                'period_no' => $period['period_no'],
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
                'months' => $period['months'],
                'interest_amount' => $period['interest_amount'],
                'closing_balance' => $period['closing_balance'],
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Investment::class);

        $data = $request->validate([
            'investment_product_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'terms' => ['accepted'],
        ]);

        $product = InvestmentProduct::query()
            ->where('is_active', true)
            ->findOrFail($data['investment_product_id']);

        try {
            $investment = $this->investments->open(
                member: $this->membership(),
                product: $product,
                amount: Money::toMinor($data['amount']),
                actor: $this->actor(),
                startDate: $data['start_date'] ?? null,
                fundingSource: 'wallet',
                notes: $data['notes'] ?? null
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('portal.investments.show', $investment)
            ->with('status', 'Investment placed. '.Money::format($investment->principal).' has moved from your wallet into '.$product->name.'.');
    }

    public function show(Investment $investment): View
    {
        $this->authorize('view', $investment);

        $this->investments->accrueDueInterest($investment);
        $investment->refresh()->load(['product', 'schedules', 'transactions']);

        return view('portal.investments.show', [
            'investment' => $investment,
            'canWithdraw' => $investment->isOpen()
                && ($investment->isMatured() || (bool) $investment->product?->early_withdrawal_allowed),
        ]);
    }

    public function withdraw(Request $request, Investment $investment): RedirectResponse
    {
        $this->authorize('withdraw', $investment);

        try {
            $transaction = $this->investments->withdraw(
                investment: $investment,
                destination: 'wallet',
                actor: $this->actor()
            );
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('portal.investments.show', $investment)
            ->with('status', 'Investment settled. '.Money::format($transaction->amount).' has been credited to your wallet.');
    }

    public function statement(Investment $investment)
    {
        $this->authorize('view', $investment);

        $this->investments->accrueDueInterest($investment);
        $investment->refresh()->load('transactions');
        $transactions = $investment->transactions()->orderBy('transaction_date')->orderBy('id')->get();

        return $this->csv('investment-'.$investment->reference_no.'.csv', [
            'Date', 'Type', 'Direction', 'Amount', 'Balance after', 'Reference', 'Description',
        ], $transactions->map(fn (InvestmentTransaction $transaction) => [
            $transaction->transaction_date?->toDateString(),
            $transaction->type,
            $transaction->direction,
            number_format($transaction->amount / 100, 2, '.', ''),
            number_format($transaction->balance_after / 100, 2, '.', ''),
            $transaction->reference,
            $transaction->description,
        ]));
    }
}
