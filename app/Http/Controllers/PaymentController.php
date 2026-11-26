<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('payments.view');

        $intents = PaymentIntent::query()
            ->with('member')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('purpose'), fn ($query) => $query->where('purpose', $request->string('purpose')))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('payments.index', [
            'intents' => $intents,
            'statuses' => ['pending', 'processing', 'succeeded', 'failed', 'cancelled'],
            'purposes' => ['wallet_topup', 'savings_deposit', 'loan_repayment'],
            'members' => Member::query()->active()->orderBy('first_name')->get(),
        ]);
    }

    public function show(PaymentIntent $intent): View
    {
        $this->requirePermission('payments.view');

        $intent->load('member');

        return view('payments.show', ['intent' => $intent]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('payments.initiate');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'purpose' => ['required', 'in:wallet_topup,savings_deposit,loan_repayment'],
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
            'savings_account_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
        ]);

        $member = Member::query()->findOrFail($data['member_id']);

        try {
            $intent = $this->payments->initiate($member, [
                'purpose' => $data['purpose'],
                'amount' => (int) round(((float) $data['amount']) * 100),
                'phone' => $data['phone'],
                'payload' => array_filter([
                    'savings_account_id' => $data['savings_account_id'] ?? null,
                    'loan_id' => $data['loan_id'] ?? null,
                ]),
            ]);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payments.show', $intent)
            ->with('status', 'Payment request sent. Awaiting provider confirmation.');
    }
}
