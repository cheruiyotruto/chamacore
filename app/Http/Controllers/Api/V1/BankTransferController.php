<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\GroupSetting;
use App\Models\Member;
use App\Models\PaymentDestination;
use App\Models\Payout;
use App\Services\AuditService;
use App\Services\PayoutService;
use App\Support\Money;
use App\Support\PayoutAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    public function __construct(private readonly PayoutService $payouts)
    {
    }

    private function isPayoutManager(): bool
    {
        return $this->context()->hasPermission('payouts.manage');
    }

    private function isPayoutViewer(): bool
    {
        return $this->isPayoutManager() || $this->context()->hasPermission('payouts.view');
    }

    /**
     * Bank-transfer APIs are available to staff with payout permissions and to
     * members explicitly allowed to send money from their own wallet.
     */
    private function ensurePayoutAccess(): void
    {
        abort_unless(
            $this->isPayoutViewer() || PayoutService::memberAccessEnabled($this->group(), $this->membership()),
            403,
            'Bank transfers are not enabled for your account.'
        );
    }

    public function access(): JsonResponse
    {
        $this->requirePermission('payouts.view');

        return response()->json([
            'members_enabled' => PayoutAccess::groupEnabled($this->group()),
            'members' => Member::withoutGlobalScopes()
                ->where('group_id', $this->group()->id)
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Member $member) => [
                    'id' => $member->id,
                    'name' => $member->full_name,
                    'can_bank_transfer' => $member->can_bank_transfer,
                ]),
        ]);
    }

    public function updateAccess(Request $request): JsonResponse
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

        $allowed = collect($data['members'] ?? [])->filter(fn ($value) => $value)->keys()->all();

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

        return response()->json([
            'message' => 'Member bank transfer access updated.',
            'members_enabled' => (bool) ($data['members_enabled'] ?? false),
        ]);
    }

    public function destinations(): JsonResponse
    {
        $this->ensurePayoutAccess();

        return response()->json([
            'data' => PaymentDestination::query()->where('is_active', true)->orderBy('name')->get()->map(fn ($destination) => [
                'id' => $destination->id,
                'uuid' => $destination->uuid,
                'name' => $destination->name,
                'type' => $destination->type,
                'label' => $destination->label(),
                'provider' => $destination->provider(),
                'is_active' => $destination->is_active,
            ]),
        ]);
    }

    public function storeDestination(Request $request): JsonResponse
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

        $destination = PaymentDestination::withoutGlobalScopes()->create([
            ...array_filter($data, fn ($value) => $value !== null && $value !== ''),
            'group_id' => $this->group()->id,
            'currency' => $this->group()->currency ?: 'KES',
            'is_active' => true,
            'created_by' => $this->actor()->id,
        ]);

        return response()->json(['destination' => $this->destinationPayload($destination)], 201);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->ensurePayoutAccess();

        $data = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:payment_destinations,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $destination = PaymentDestination::query()->findOrFail($data['destination_id']);

        try {
            $quote = $this->payouts->quote(
                $this->group(),
                Money::toMinor($data['amount']),
                $destination->provider()
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['quote' => $quote]);
    }

    public function index(): JsonResponse
    {
        $this->ensurePayoutAccess();

        $query = Payout::query()->with('destination')->orderByDesc('created_at');

        // Members only ever see their own wallet-funded transfers.
        if (! $this->isPayoutViewer()) {
            $query->where('member_id', $this->membership()->id)
                ->where('source', Payout::SOURCE_WALLET);
        }

        return response()->json([
            'data' => $query->limit(100)->get()->map(fn (Payout $payout) => $this->payoutPayload($payout)),
        ]);
    }

    public function show(Payout $payout): JsonResponse
    {
        $this->ensurePayoutAccess();

        $staff = $this->isPayoutViewer();

        if (! $staff) {
            abort_unless(
                $payout->member_id === $this->membership()->id
                    && $payout->source === Payout::SOURCE_WALLET,
                403,
                'You can only view your own transfers.'
            );
        }

        return response()->json(['payout' => $this->payoutPayload($payout, $staff)]);
    }

    public function store(Request $request): JsonResponse
    {
        $canManage = $this->isPayoutManager();

        if (! $canManage) {
            abort_unless(
                PayoutService::memberAccessEnabled($this->group(), $this->membership()),
                403,
                'Bank transfers are not enabled for your account.'
            );
        }

        $rules = [
            'destination_id' => ['required', 'integer', 'exists:payment_destinations,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];

        if ($canManage) {
            $rules += [
                'source' => ['sometimes', 'in:bill_collections,wallet'],
                'bill_id' => ['nullable', 'integer', 'exists:bills,id'],
                'member_id' => ['nullable', 'integer', 'exists:members,id'],
                'manual_charge' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        $data = $request->validate($rules);

        // Privileged fields are never accepted from a member, even if supplied.
        $data['source'] = $canManage ? ($data['source'] ?? Payout::SOURCE_BILLS) : Payout::SOURCE_WALLET;
        $data['member_id'] = $canManage ? ($data['member_id'] ?? null) : $this->membership()->id;
        $data['bill_id'] = $canManage ? ($data['bill_id'] ?? null) : null;
        $data['manual_charge'] = $canManage ? ($data['manual_charge'] ?? null) : null;

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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Transfer submitted. A provider callback will confirm completion.',
            'payout' => $this->payoutPayload($payout, true),
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function destinationPayload(PaymentDestination $destination): array
    {
        return [
            'id' => $destination->id,
            'uuid' => $destination->uuid,
            'name' => $destination->name,
            'type' => $destination->type,
            'label' => $destination->label(),
            'provider' => $destination->provider(),
            'is_active' => $destination->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutPayload(Payout $payout, bool $detailed = false): array
    {
        $data = [
            'id' => $payout->id,
            'uuid' => $payout->uuid,
            'destination' => $payout->destination ? $this->destinationPayload($payout->destination) : null,
            'source' => $payout->source,
            'bill_id' => $payout->bill_id,
            'amount' => $payout->amount,
            'net_amount' => $payout->net_amount,
            'charge_amount' => $payout->charge_amount,
            'total_amount' => $payout->total_amount,
            'charge_breakdown' => $payout->charge_breakdown,
            'provider' => $payout->provider,
            'status' => $payout->status,
            'provider_reference' => $payout->provider_reference,
            'failure_reason' => $payout->failure_reason,
            'created_at' => $payout->created_at?->toIso8601String(),
            'paid_at' => $payout->paid_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['payload'] = $payout->payload;
        }

        return $data;
    }
}
