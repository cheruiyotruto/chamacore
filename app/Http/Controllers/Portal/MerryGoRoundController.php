<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\MerryGoRoundContribution;
use App\Models\MerryGoRoundCycle;
use App\Models\MerryGoRoundParticipant;
use App\Services\MerryGoRoundService;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MerryGoRoundController extends Controller
{
    public function __construct(
        private readonly MerryGoRoundService $mgr,
        private readonly WalletService $wallets,
    ) {
    }

    public function index(): View
    {
        $this->authorize('viewAny', MerryGoRoundCycle::class);

        $member = $this->membership();

        $participations = MerryGoRoundParticipant::query()
            ->with(['cycle', 'payout'])
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('portal.mgr.index', [
            'participations' => $participations,
        ]);
    }

    public function show(Request $request, MerryGoRoundCycle $cycle): View
    {
        $this->authorize('view', $cycle);

        $member = $this->membership();

        $participant = MerryGoRoundParticipant::query()
            ->where('merry_go_round_cycle_id', $cycle->id)
            ->where('member_id', $member->id)
            ->firstOrFail();

        $contributions = MerryGoRoundContribution::query()
            ->where('participant_id', $participant->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('due_date')
            ->paginate(25)
            ->withQueryString();

        $payable = $participant->contributions()
            ->whereIn('status', ['pending', 'late'])
            ->orderBy('due_date')
            ->limit(10)
            ->get()
            ->map(fn (MerryGoRoundContribution $contribution) => [
                'contribution' => $contribution,
                ...$this->mgr->portalAmount($contribution),
            ]);

        return view('portal.mgr.show', [
            'cycle' => $cycle,
            'participant' => $participant->load('payout'),
            'contributions' => $contributions,
            'payable' => $payable,
            'walletBalance' => $this->wallets->ensureWallet($member)->available_balance,
            'summary' => [
                'due' => (int) $participant->contributions()->whereIn('status', ['pending', 'late'])->sum('amount'),
                'paid' => (int) $participant->contributions()->where('status', 'paid')->sum('amount'),
                'penalties' => (int) $participant->contributions()->sum('penalty_amount'),
            ],
            'statuses' => ['pending', 'paid', 'late', 'waived'],
        ]);
    }

    public function payFromWallet(Request $request, MerryGoRoundContribution $contribution): RedirectResponse
    {
        $member = $this->membership();
        $contribution = $this->ownedContribution($member->id, $contribution);

        $data = $request->validate([
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $payment = $this->mgr->payFromWallet(
                $member,
                $contribution,
                isset($data['manual_charge']) && $data['manual_charge'] !== ''
                    ? Money::toMinor($data['manual_charge'])
                    : null,
                $this->actor()
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Contribution paid from your wallet. Reference '.$payment->reference.'.');
    }

    public function payWithMpesa(
        Request $request,
        MerryGoRoundContribution $contribution,
        PaymentService $payments
    ): RedirectResponse {
        $member = $this->membership();
        $contribution = $this->ownedContribution($member->id, $contribution);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        $totals = $this->mgr->portalAmount($contribution);

        try {
            $intent = $payments->initiate($member, [
                'purpose' => 'mgr_contribution',
                'amount' => $totals['total'],
                'phone' => $data['phone'],
                'payload' => array_filter([
                    'contribution_id' => $contribution->id,
                    'penalty_amount' => $totals['penalty'],
                    'manual_charge' => isset($data['manual_charge']) && $data['manual_charge'] !== ''
                        ? Money::toMinor($data['manual_charge'])
                        : null,
                ], fn ($value) => $value !== null),
            ], 'mgr-'.$contribution->id.'-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Approve the M-Pesa request on your phone. Reference: '.$intent->uuid);
    }

    private function ownedContribution(int $memberId, MerryGoRoundContribution $contribution): MerryGoRoundContribution
    {
        abort_unless($contribution->member_id === $memberId, 403, 'This contribution does not belong to you.');

        return $contribution;
    }
}
