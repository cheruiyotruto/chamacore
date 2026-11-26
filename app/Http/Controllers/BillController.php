<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Member;
use App\Services\BillService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillController extends Controller
{
    public function __construct(private readonly BillService $bills)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('bills.view');

        $bills = Bill::query()
            ->with(['member', 'payments'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('bills.index', [
            'bills' => $bills,
            'statuses' => [
                Bill::STATUS_PENDING, Bill::STATUS_PARTIALLY_PAID, Bill::STATUS_OVERDUE,
                Bill::STATUS_PAID, Bill::STATUS_CANCELLED,
            ],
            'canManage' => $this->context()->hasPermission('bills.manage'),
        ]);
    }

    public function create(): View
    {
        $this->requirePermission('bills.manage');

        return view('bills.create', [
            'members' => Member::active()->orderBy('first_name')->get(),
            'statuses' => [Bill::STATUS_PENDING, Bill::STATUS_OVERDUE],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('bills.manage');

        $data = $request->validate([
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'payee_name' => ['required', 'string', 'max:150'],
            'payee_account' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', 'in:pending,overdue'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['member_id'] && ! Member::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->whereKey($data['member_id'])->exists()) {
            return back()->withInput()->with('error', 'Select a member from this group.');
        }

        $bill = $this->bills->create($this->group(), [
            'member_id' => $data['member_id'] ?: null,
            'payee_name' => $data['payee_name'],
            'payee_account' => $data['payee_account'] ?: null,
            'phone' => $data['phone'] ?: null,
            'amount' => Money::toMinor($data['amount']),
            'currency' => $this->group()->currency ?: 'KES',
            'due_date' => $data['due_date'],
            'status' => $data['status'],
            'description' => $data['description'] ?: null,
        ], $this->actor());

        return redirect()->route('bills.show', $bill)->with('status', 'Bill '.$bill->bill_no.' created.');
    }

    public function show(Bill $bill): View
    {
        $this->requirePermission('bills.view');

        $bill->load(['member', 'payments.recorder']);

        return view('bills.show', [
            'bill' => $bill,
            'members' => Member::active()->orderBy('first_name')->get(),
            'canManage' => $this->context()->hasPermission('bills.manage'),
        ]);
    }

    public function recordPayment(Request $request, Bill $bill): RedirectResponse
    {
        $this->requirePermission('bills.manage');

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank,wallet'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $payer = Member::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->whereKey($data['member_id'])
            ->first();

        if (! $payer) {
            return back()->withInput()->with('error', 'Select a member from this group.');
        }

        $manual = isset($data['manual_charge']) && $data['manual_charge'] !== ''
            ? Money::toMinor($data['manual_charge'])
            : null;

        try {
            if ($data['method'] === 'wallet') {
                $payment = $this->bills->payWithWallet(
                    $payer,
                    $bill,
                    Money::toMinor($data['amount']),
                    $manual,
                    $this->actor()
                );
            } else {
                $payment = $this->bills->payAtCounter(
                    $this->group(),
                    $bill,
                    $payer,
                    Money::toMinor($data['amount']),
                    $data['method'],
                    $manual,
                    $this->actor(),
                    $data['reference'] ?? null
                );
            }
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Payment '.$payment->reference.' recorded.');
    }

    public function cancel(Bill $bill): RedirectResponse
    {
        $this->requirePermission('bills.manage');

        if ($bill->amount_paid > 0) {
            return back()->with('error', 'A bill with payments cannot be cancelled.');
        }

        $bill->update(['status' => Bill::STATUS_CANCELLED]);

        return back()->with('status', 'Bill cancelled.');
    }
}
