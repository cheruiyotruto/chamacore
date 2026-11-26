<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Bill;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\PaymentDestination;
use App\Models\Payout;
use App\Services\AuditService;
use App\Services\PayoutService;
use App\Support\Money;
use App\Support\PayoutAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff UI for sending money to bank accounts and M-Pesa organisations.
 * The product label is "Bank transfers"; both transports are supported.
 */
class BankTransferController extends Controller
{
    public function __construct(private readonly PayoutService $payouts)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('payouts.view');

        $destinations = PaymentDestination::query()
            ->with('member')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $payouts = Payout::query()
            ->with(['destination', 'bill', 'member', 'requester'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('bank-transfers.index', [
            'destinations' => $destinations,
            'payouts' => $payouts,
            'types' => PaymentDestination::TYPES,
            'statuses' => [
                Payout::STATUS_PENDING, Payout::STATUS_PROCESSING, Payout::STATUS_SUCCEEDED,
                Payout::STATUS_FAILED, Payout::STATUS_CANCELLED,
            ],
            'sources' => [
                Payout::SOURCE_BILLS => 'Collected bill funds',
                Payout::SOURCE_WALLET => 'Member wallet',
            ],
            'members' => Member::active()->orderBy('first_name')->get(),
            'groupAccessEnabled' => PayoutAccess::groupEnabled($this->group()),
            'bills' => Bill::query()->whereIn('status', [
                Bill::STATUS_PAID, Bill::STATUS_PARTIALLY_PAID, Bill::STATUS_OVERDUE,
            ])->orderByDesc('created_at')->limit(100)->get(),
            'canManage' => $this->context()->hasPermission('payouts.manage'),
        ]);
    }

    public function show(Payout $payout): View
    {
        $this->requirePermission('payouts.view');

        $payout->load(['destination', 'bill', 'member', 'requester', 'approver', 'journalEntry']);

        return view('bank-transfers.show', [
            'payout' => $payout,
            'canManage' => $this->context()->hasPermission('payouts.manage'),
        ]);
    }

    public function storeDestination(Request $request): RedirectResponse
    {
        $this->requirePermission('payouts.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:bank,paybill,till,phone'],
            'bank_name' => ['required_if:type,bank', 'nullable', 'string', 'max:120'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['required_if:type,bank', 'nullable', 'string', 'max:100'],
            'paybill_number' => ['required_if:type,paybill', 'nullable', 'string', 'max:30'],
            'till_number' => ['required_if:type,till', 'nullable', 'string', 'max:30'],
            'phone' => ['required_if:type,phone', 'nullable', 'string', 'max:30'],
        ]);

        PaymentDestination::withoutGlobalScopes()->create([
            ...array_filter($data, fn ($value) => $value !== null && $value !== ''),
            'group_id' => $this->group()->id,
            'currency' => $this->group()->currency ?: 'KES',
            'is_active' => true,
            'created_by' => $this->actor()->id,
        ]);

        return back()->with('status', 'Payment destination saved.');
    }

    public function updateAccess(Request $request): RedirectResponse
    {
        $this->requirePermission('payouts.manage');

        $data = $request->validate([
            'members_enabled' => ['nullable', 'boolean'],
            'members' => ['nullable', 'array'],
            'members.*' => ['boolean'],
        ]);

        GroupSetting::withoutGlobalScopes()->updateOrCreate(
            ['group_id' => $this->group()->id, 'key' => PayoutAccess::KEY],
            ['value' => PayoutAccess::normalize(['members_enabled' => (bool) ($data['members_enabled'] ?? false)])]
        );

        $allowed = collect($data['members'] ?? [])
            ->filter(fn ($value) => $value)
            ->keys()
            ->all();

        Member::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->update(['can_bank_transfer' => false]);

        if ($allowed !== []) {
            Member::withoutGlobalScopes()
                ->where('group_id', $this->group()->id)
                ->whereIn('id', $allowed)
                ->update(['can_bank_transfer' => true]);
        }

        AuditService::log('payout.member_access.updated', [
            'members' => $allowed,
            'group_enabled' => (bool) ($data['members_enabled'] ?? false),
        ], $this->group());

        return back()->with('status', 'Member bank transfer access updated.');
    }

    public function toggleDestination(PaymentDestination $destination): RedirectResponse
    {
        $this->requirePermission('payouts.manage');

        $destination->update(['is_active' => ! $destination->is_active]);

        return back()->with('status', $destination->is_active
            ? 'Payment destination enabled.'
            : 'Payment destination disabled.');
    }

    public function storePayout(Request $request): RedirectResponse
    {
        $this->requirePermission('payouts.manage');

        $data = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:payment_destinations,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'source' => ['required', 'in:bill_collections,wallet'],
            'bill_id' => ['nullable', 'integer', 'exists:bills,id'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $payout = $this->payouts->create($this->group(), [
                ...$data,
                'amount' => Money::toMinor($data['amount']),
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

        return redirect()->route('bank-transfers.show', $payout)
            ->with('status', 'Transfer submitted. Reference '.$payout->uuid.'.');
    }

    public function cancel(Payout $payout): RedirectResponse
    {
        $this->requirePermission('payouts.manage');

        try {
            $this->payouts->cancel($payout, 'Cancelled by '.$this->actor()->name);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Transfer cancelled and funds released.');
    }
}
