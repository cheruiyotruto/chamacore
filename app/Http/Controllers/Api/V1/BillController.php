<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\BillService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BillController extends Controller
{
    public function __construct(private readonly BillService $bills)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $member = $this->membership();

        $bills = Bill::query()
            ->where('member_id', $member->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate((int) min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $bills->through(fn (Bill $bill) => $this->payload($bill)),
        ]);
    }

    public function show(Bill $bill): JsonResponse
    {
        abort_unless($bill->member_id === $this->membership()->id, 403);

        $bill->load('payments');

        return response()->json(['bill' => $this->payload($bill, true)]);
    }

    public function pay(Request $request, Bill $bill, PaymentService $payments): JsonResponse
    {
        $member = $this->membership();

        abort_unless($bill->member_id === $member->id, 403);

        $data = $request->validate([
            'method' => ['required', 'in:wallet,mpesa'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'phone' => ['required_if:method,mpesa', 'nullable', 'string', 'max:30'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        $amount = Money::toMinor($data['amount']);
        $manual = isset($data['manual_charge']) && $data['manual_charge'] !== ''
            ? Money::toMinor($data['manual_charge'])
            : null;

        try {
            if ($data['method'] === 'wallet') {
                $payment = $this->bills->payWithWallet($member, $bill, $amount, $manual, $this->actor());

                return response()->json([
                    'message' => 'Bill payment completed.',
                    'payment' => $this->paymentPayload($payment),
                    'bill' => $this->payload($bill->refresh(), true),
                ]);
            }

            $intent = $payments->initiate($member, [
                'purpose' => 'bill_payment',
                'amount' => $amount,
                'phone' => $data['phone'],
                'payload' => array_filter([
                    'bill_id' => $bill->id,
                    'manual_charge' => $manual,
                ], fn ($value) => $value !== null),
            ], 'api-bill-'.$bill->id.'-'.Str::uuid());

            return response()->json([
                'message' => 'Approve the M-Pesa request on your phone to complete the payment.',
                'payment' => [
                    'uuid' => $intent->uuid,
                    'status' => $intent->status,
                    'amount' => $intent->amount,
                    'provider_reference' => $intent->provider_reference,
                ],
            ], 202);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Bill $bill, bool $detailed = false): array
    {
        $data = [
            'id' => $bill->id,
            'uuid' => $bill->uuid,
            'bill_no' => $bill->bill_no,
            'payee_name' => $bill->payee_name,
            'payee_account' => $bill->payee_account,
            'amount' => $bill->amount,
            'amount_paid' => $bill->amount_paid,
            'outstanding' => $bill->outstanding(),
            'currency' => $bill->currency,
            'due_date' => $bill->due_date?->toDateString(),
            'status' => $bill->status,
            'description' => $bill->description,
            'created_at' => $bill->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['payments'] = $bill->payments->map(fn ($payment) => $this->paymentPayload($payment))->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload($payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'settlement_amount' => $payment->settlement_amount,
            'charge_amount' => $payment->charge_amount,
            'charge_breakdown' => $payment->charge_breakdown,
            'borne_by' => $payment->borne_by,
            'method' => $payment->method,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
