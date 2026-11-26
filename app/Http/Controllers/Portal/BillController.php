<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\BillService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BillController extends Controller
{
    public function __construct(private readonly BillService $bills)
    {
    }

    public function index(): View
    {
        $member = $this->membership();

        $bills = Bill::query()
            ->where('member_id', $member->id)
            ->with('payments')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('portal.bills.index', ['bills' => $bills]);
    }

    public function show(Bill $bill): View
    {
        $member = $this->membership();

        abort_unless($bill->member_id === $member->id, 403, 'This bill does not belong to you.');

        $bill->load('payments');

        return view('portal.bills.show', ['bill' => $bill]);
    }

    public function payFromWallet(Request $request, Bill $bill): RedirectResponse
    {
        $member = $this->membership();

        abort_unless($bill->member_id === $member->id, 403, 'This bill does not belong to you.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $payment = $this->bills->payWithWallet(
                $member,
                $bill,
                Money::toMinor($data['amount']),
                isset($data['manual_charge']) && $data['manual_charge'] !== ''
                    ? Money::toMinor($data['manual_charge'])
                    : null,
                $this->actor()
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Bill paid. Reference '.$payment->reference.'.');
    }

    public function payWithMpesa(Request $request, Bill $bill, PaymentService $payments): RedirectResponse
    {
        $member = $this->membership();

        abort_unless($bill->member_id === $member->id, 403, 'This bill does not belong to you.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'phone' => ['required', 'string', 'max:30'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $intent = $payments->initiate($member, [
                'purpose' => 'bill_payment',
                'amount' => Money::toMinor($data['amount']),
                'phone' => $data['phone'],
                'payload' => array_filter([
                    'bill_id' => $bill->id,
                    'manual_charge' => isset($data['manual_charge']) && $data['manual_charge'] !== ''
                        ? Money::toMinor($data['manual_charge'])
                        : null,
                ], fn ($value) => $value !== null),
            ], 'bill-'.$bill->id.'-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Approve the M-Pesa request on your phone. Reference: '.$intent->uuid);
    }
}
