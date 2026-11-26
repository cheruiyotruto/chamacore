<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Services\LedgerService;
use App\Services\SavingsService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SavingsController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('savings.view');

        $accounts = SavingsAccount::with(['member', 'product'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('account_no', 'like', $search)
                        ->orWhereHas('member', fn ($q) => $q
                            ->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search)
                            ->orWhere('member_no', 'like', $search));
                });
            })
            ->when($request->filled('product'), fn ($query) => $query->where('savings_product_id', $request->integer('product')))
            ->orderBy('account_no')
            ->paginate(20)
            ->withQueryString();

        return view('savings.index', [
            'accounts' => $accounts,
            'products' => SavingsProduct::withCount('accounts')->orderBy('name')->get(),
            'accountsTotal' => (int) SavingsAccount::sum('balance'),
            'pledgedTotal' => (int) SavingsAccount::sum('pledged_amount'),
            'members' => Member::query()->active()->orderBy('first_name')->get(),
            'canManage' => $this->context()->hasPermission('savings.manage'),
        ]);
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $this->requirePermission('savings.view');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'type' => ['required', Rule::in(['mandatory', 'voluntary', 'emergency', 'shares', 'welfare', 'special'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_balance' => ['nullable', 'numeric', 'min:0'],
            'contribution_amount' => ['nullable', 'numeric', 'min:0'],
            'contribution_frequency' => ['nullable', Rule::in(['weekly', 'monthly', 'none'])],
            'allows_withdrawal' => ['nullable', 'boolean'],
            'qualifies_for_loan' => ['nullable', 'boolean'],
            'is_mandatory' => ['nullable', 'boolean'],
        ]);

        $exists = SavingsProduct::where('code', $data['code'])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['code' => 'A savings product with this code already exists.']);
        }

        SavingsProduct::create([
            'name' => $data['name'],
            'code' => Str::upper($data['code']),
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'interest_rate_bps' => (int) round(((float) ($data['interest_rate'] ?? 0)) * 100),
            'interest_period' => 'annual',
            'min_balance' => Money::toMinor($data['min_balance'] ?? 0),
            'contribution_amount' => isset($data['contribution_amount']) ? Money::toMinor($data['contribution_amount']) : null,
            'contribution_frequency' => $data['contribution_frequency'] ?? 'none',
            'allows_withdrawal' => $request->boolean('allows_withdrawal'),
            'qualifies_for_loan' => $request->boolean('qualifies_for_loan', true),
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_active' => true,
        ]);

        return back()->with('status', 'Savings product created.');
    }

    public function updateProduct(Request $request, SavingsProduct $product): RedirectResponse
    {
        $this->requirePermission('savings.view');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'type' => ['required', Rule::in(['mandatory', 'voluntary', 'emergency', 'shares', 'welfare', 'special'])],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_balance' => ['nullable', 'numeric', 'min:0'],
            'contribution_amount' => ['nullable', 'numeric', 'min:0'],
            'contribution_frequency' => ['nullable', Rule::in(['weekly', 'monthly', 'none'])],
            'allows_withdrawal' => ['nullable', 'boolean'],
            'qualifies_for_loan' => ['nullable', 'boolean'],
            'is_mandatory' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $duplicate = SavingsProduct::where('code', Str::upper($data['code']))
            ->whereKeyNot($product->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => 'A savings product with this code already exists.']);
        }

        $product->update([
            'name' => $data['name'],
            'code' => Str::upper($data['code']),
            'type' => $data['type'],
            'interest_rate_bps' => (int) round(((float) ($data['interest_rate'] ?? 0)) * 100),
            'min_balance' => Money::toMinor($data['min_balance'] ?? 0),
            'contribution_amount' => isset($data['contribution_amount']) ? Money::toMinor($data['contribution_amount']) : null,
            'contribution_frequency' => $data['contribution_frequency'] ?? 'none',
            'allows_withdrawal' => $request->boolean('allows_withdrawal'),
            'qualifies_for_loan' => $request->boolean('qualifies_for_loan'),
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Savings product updated.');
    }

    public function openAccount(Request $request, SavingsService $savings): RedirectResponse
    {
        $this->requirePermission('savings.deposit');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'savings_product_id' => ['required', 'integer'],
        ]);

        $member = Member::where('status', 'active')->findOrFail($data['member_id']);
        $product = SavingsProduct::where('is_active', true)->findOrFail($data['savings_product_id']);

        $account = $savings->openAccount($member, $product);

        return redirect()->route('savings.show', $account)->with('status', 'Savings account opened.');
    }

    public function show(Request $request, SavingsAccount $account): View
    {
        $this->authorize('view', $account);

        $account->load(['member', 'product']);

        $transactions = $account->transactions()
            ->when($request->filled('from'), fn ($query) => $query->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('transaction_date', '<=', $request->date('to')))
            ->latest('transaction_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('savings.show', [
            'account' => $account,
            'transactions' => $transactions,
            'ledger' => app(LedgerService::class),
        ]);
    }

    public function deposit(Request $request, SavingsAccount $account, SavingsService $savings): RedirectResponse
    {
        $this->authorize('deposit', $account);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank', 'wallet'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $member = $account->member;
        $amount = Money::toMinor($data['amount']);
        $reference = $data['reference'] ?: 'SAV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));

        try {
            $savings->deposit(
                member: $member,
                account: $account,
                amount: $amount,
                type: 'deposit',
                counterAccountCode: $data['method'] === 'bank' ? LedgerService::BANK : LedgerService::CASH,
                reference: $reference,
                idempotencyKey: 'savings-deposit-'.$reference,
                description: $data['description'] ?? null,
                viaWallet: $data['method'] === 'wallet',
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Deposit of '.Money::format($amount).' recorded.');
    }

    public function withdraw(Request $request, SavingsAccount $account, SavingsService $savings): RedirectResponse
    {
        $this->authorize('withdraw', $account);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank', 'wallet'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = Money::toMinor($data['amount']);
        $reference = $data['reference'] ?: 'WDR-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));

        try {
            $savings->withdraw(
                member: $account->member,
                account: $account,
                amount: $amount,
                counterAccountCode: $data['method'] === 'bank' ? LedgerService::BANK : LedgerService::CASH,
                reference: $reference,
                idempotencyKey: 'savings-withdraw-'.$reference,
                description: $data['description'] ?? null,
                toWallet: $data['method'] === 'wallet',
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Withdrawal of '.Money::format($amount).' recorded.');
    }

    public function statement(SavingsAccount $account)
    {
        $this->authorize('view', $account);

        $transactions = $account->transactions()->orderBy('transaction_date')->orderBy('id')->get();

        return $this->csv(
            'savings-'.$account->account_no.'-'.now()->format('Ymd').'.csv',
            ['Date', 'Type', 'Direction', 'Amount', 'Balance after', 'Reference', 'Description'],
            $transactions->map(fn (SavingsTransaction $transaction) => [
                $transaction->transaction_date->toDateString(),
                $transaction->type,
                $transaction->direction,
                number_format($transaction->amount / 100, 2, '.', ''),
                number_format($transaction->balance_after / 100, 2, '.', ''),
                $transaction->reference,
                $transaction->description,
            ])
        );
    }
}
