<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SavingsController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', SavingsAccount::class);

        return view('portal.savings.index', [
            'accounts' => SavingsAccount::query()->with('product')->orderBy('savings_product_id')->get(),
            'recent' => SavingsTransaction::query()->with('account.product')->latest('transaction_date')->limit(10)->get(),
        ]);
    }

    public function show(SavingsAccount $account, Request $request): View
    {
        $this->authorize('view', $account);

        $transactions = $account->transactions()
            ->when($request->filled('from'), fn ($query) => $query->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('transaction_date', '<=', $request->date('to')))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('portal.savings.show', [
            'account' => $account->load('product'),
            'transactions' => $transactions,
        ]);
    }

    public function statement(SavingsAccount $account)
    {
        $this->authorize('view', $account);

        $transactions = $account->transactions()->orderBy('transaction_date')->orderBy('id')->get();

        return $this->csv('savings-'.$account->account_no.'.csv', [
            'Date', 'Type', 'Description', 'Credit', 'Debit', 'Balance', 'Reference',
        ], $transactions->map(fn (SavingsTransaction $transaction) => [
            $transaction->transaction_date?->toDateString(),
            $transaction->type,
            $transaction->description,
            $transaction->direction === 'credit' ? number_format($transaction->amount / 100, 2, '.', '') : '',
            $transaction->direction === 'debit' ? number_format($transaction->amount / 100, 2, '.', '') : '',
            number_format($transaction->balance_after / 100, 2, '.', ''),
            $transaction->reference,
        ]));
    }
}
