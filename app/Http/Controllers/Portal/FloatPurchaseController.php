<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\FloatPurchase;
use App\Models\MpesaAgent;
use App\Services\FloatPurchaseService;
use App\Services\Gateways\FloatGatewayResolver;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FloatPurchaseController extends Controller
{
    public function __construct(
        private readonly FloatPurchaseService $floats,
        private readonly WalletService $wallets,
        private readonly FloatGatewayResolver $gateways,
    ) {
    }

    public function index(): View
    {
        $group = $this->group();
        $member = $this->membership();

        $purchases = FloatPurchase::query()
            ->with('agent')
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->paginate(15);

        $defaultAmount = Money::toMinor(config('chama.float.packages.0', 100));

        return view('portal.float.index', [
            'wallet' => $this->wallets->ensureWallet($member),
            'purchases' => $purchases,
            'agents' => MpesaAgent::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->active()
                ->orderBy('name')
                ->get(),
            'packages' => $this->floats->packages(),
            'quote' => $this->floats->quote($group, $defaultAmount),
            'minAmount' => (int) config('chama.float.min_amount', 10),
            'maxAmount' => (int) config('chama.float.max_amount', 10000),
            'providerConfigured' => $this->gateways->configured()->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = $this->group();
        $member = $this->membership();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'agent_id' => ['nullable', 'integer'],
            'agent_name' => ['nullable', 'string', 'max:120'],
            'agent_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{4,15}$/', 'required_without:agent_id'],
            'store_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{4,15}$/', 'required_without:agent_id'],
            'phone' => ['nullable', 'string', 'max:30', 'confirmed', 'required_without:agent_id'],
            'network' => ['sometimes', 'string', 'in:mpesa'],
            'idempotency_key' => ['sometimes', 'string', 'max:120'],
        ]);

        try {
            $purchase = $this->floats->purchase($group, $member, $this->actor(), [
                'amount' => Money::toMinor($data['amount']),
                'agent_id' => $data['agent_id'] ?? null,
                'agent_name' => $data['agent_name'] ?? null,
                'agent_number' => $data['agent_number'] ?? null,
                'store_number' => $data['store_number'] ?? null,
                'phone' => $data['phone'] ?? '',
                'network' => $data['network'] ?? 'mpesa',
                'idempotency_key' => $data['idempotency_key'] ?: 'web-'.(string) Str::uuid(),
            ]);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = match ($purchase->status) {
            FloatPurchase::STATUS_COMPLETED => 'M-Pesa float delivered to store '.$purchase->store_number.'. Reference: '.$purchase->reference,
            FloatPurchase::STATUS_PROCESSING => 'Your float purchase is being processed. Reference: '.$purchase->reference,
            FloatPurchase::STATUS_FAILED => 'The purchase failed and your wallet was refunded: '.$purchase->failure_reason,
            default => 'Float purchase queued for store '.$purchase->store_number.'. Reference: '.$purchase->reference,
        };

        return redirect()->route('portal.float.index')->with('status', $message);
    }
}
