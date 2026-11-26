<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\PaymentDestination;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankTransferController extends Controller
{
    public function __construct(private readonly PayoutService $payouts)
    {
    }

    public function index(): View
    {
        $member = $this->membership();
        $group = $this->group();

        abort_unless(PayoutService::memberAccessEnabled($group, $member), 403, 'Bank transfers are not enabled for your account.');

        return view('portal.bank-transfers.index', [
            'destinations' => PaymentDestination::query()->where('is_active', true)->orderBy('name')->get(),
            'payouts' => Payout::query()
                ->where('member_id', $member->id)
                ->where('source', Payout::SOURCE_WALLET)
                ->with('destination')
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $member = $this->membership();
        $group = $this->group();

        abort_unless(PayoutService::memberAccessEnabled($group, $member), 403, 'Bank transfers are not enabled for your account.');

        $data = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:payment_destinations,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $payout = $this->payouts->create($group, [
                'destination_id' => $data['destination_id'],
                'amount' => Money::toMinor($data['amount']),
                'source' => Payout::SOURCE_WALLET,
                'member_id' => $member->id,
                'manual_charge' => isset($data['manual_charge']) && $data['manual_charge'] !== ''
                    ? Money::toMinor($data['manual_charge'])
                    : null,
            ], $this->actor());

            $payout = $this->payouts->initiate($payout, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Transfer submitted. Reference '.$payout->uuid.'.');
    }
}
