<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\Loan;
use App\Models\Member;
use App\Models\MerryGoRoundContribution;
use App\Models\SavingsAccount;
use App\Models\TellerEntry;
use App\Models\TellerSession;
use App\Models\Wallet;
use App\Services\ChargeService;
use App\Services\TellerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class TellerController extends Controller
{
    public function __construct(
        private readonly TellerService $teller,
        private readonly ChargeService $charges,
    ) {}

    public function index(Request $request): View
    {
        $this->requirePermission('teller.view');

        $group = $this->group();
        $actor = $this->actor();

        $session = TellerSession::query()
            ->with(['entries' => fn ($query) => $query->with('member')->latest()->limit(50)])
            ->where('user_id', $actor->id)
            ->where('status', TellerSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();

        $search = trim((string) $request->query('q', ''));
        $members = collect();

        if (mb_strlen($search) >= 2) {
            $like = '%'.$search.'%';

            $members = Member::query()
                ->where(function ($query) use ($like) {
                    $query->where('member_no', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                })
                ->orderBy('first_name')
                ->limit(20)
                ->get();
        }

        $recentEntries = TellerEntry::query()
            ->with(['member', 'session.teller'])
            ->latest()
            ->limit(15)
            ->get();

        $closedSessions = TellerSession::query()
            ->with('teller')
            ->where('status', TellerSession::STATUS_CLOSED)
            ->latest('closed_at')
            ->limit(10)
            ->get();

        $pendingCheques = TellerEntry::query()
            ->where('method', TellerEntry::METHOD_CHEQUE)
            ->where('status', TellerEntry::STATUS_PENDING)
            ->count();

        return view('teller.index', [
            'group' => $group,
            'session' => $session,
            'search' => $search,
            'members' => $members,
            'recentEntries' => $recentEntries,
            'closedSessions' => $closedSessions,
            'pendingCheques' => $pendingCheques,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function member(Request $request, Member $member): View
    {
        $this->requirePermission('teller.view');

        $actor = $this->actor();

        $accounts = SavingsAccount::query()
            ->with('product')
            ->where('member_id', $member->id)
            ->orderBy('id')
            ->get();

        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DISBURSED, Loan::STATUS_RESTRUCTURED])
            ->orderBy('id')
            ->get();

        $contributions = MerryGoRoundContribution::query()
            ->with('cycle')
            ->where('member_id', $member->id)
            ->whereIn('status', ['pending', 'partial'])
            ->whereHas('cycle', fn ($query) => $query->where('status', 'active'))
            ->orderBy('due_date')
            ->limit(20)
            ->get();

        $investments = Investment::query()
            ->with('product')
            ->where('member_id', $member->id)
            ->whereIn('status', [Investment::STATUS_ACTIVE, Investment::STATUS_MATURED])
            ->orderBy('id')
            ->get();

        $investmentProducts = InvestmentProduct::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $wallet = Wallet::query()->where('member_id', $member->id)->first();

        $session = TellerSession::query()
            ->where('user_id', $actor->id)
            ->where('status', TellerSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();

        $entries = TellerEntry::query()
            ->with('session')
            ->where('member_id', $member->id)
            ->latest()
            ->limit(20)
            ->get();

        return view('teller.member', [
            'member' => $member,
            'session' => $session,
            'accounts' => $accounts,
            'loans' => $loans,
            'contributions' => $contributions,
            'investments' => $investments,
            'investmentProducts' => $investmentProducts,
            'wallet' => $wallet,
            'entries' => $entries,
            'applicableCharges' => $this->charges->applicableForMember($this->group(), $member),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function openSession(Request $request): RedirectResponse
    {
        $this->requirePermission('teller.operate');

        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $session = $this->teller->openSession(
                $this->group(),
                $this->actor(),
                Money::toMinor($data['opening_float']),
                $data['notes'] ?? null,
            );
        } catch (BusinessRuleException $exception) {
            return back()->withErrors(['teller' => $exception->getMessage()]);
        }

        return redirect()
            ->route('teller.index')
            ->with('status', 'Till opened with '.Money::format($session->opening_float).' opening float.');
    }

    public function closeSession(Request $request, TellerSession $session): RedirectResponse
    {
        $this->requireAnyPermission(['teller.operate', 'teller.reconcile']);

        if ($session->user_id !== $this->actor()->id && ! $this->context()->hasPermission('teller.reconcile')) {
            abort(403, 'Only the till owner can close this session.');
        }

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $closed = $this->teller->closeSession(
                $session,
                Money::toMinor($data['counted_cash']),
                $data['closing_notes'] ?? null,
                $this->actor(),
            );
        } catch (BusinessRuleException $exception) {
            return back()->withErrors(['teller' => $exception->getMessage()]);
        }

        $message = $closed->variance === 0
            ? 'Till closed and balanced.'
            : 'Till closed with a '.Money::format(abs($closed->variance)).' '.($closed->variance > 0 ? 'overage' : 'shortage').'.';

        return redirect()
            ->route('teller.sessions.show', $closed)
            ->with('status', $message);
    }

    public function showSession(TellerSession $session): View
    {
        $this->requirePermission('teller.view');

        $session->load([
            'teller',
            'entries' => fn ($query) => $query->with(['member', 'journalEntry'])->latest(),
        ]);

        return view('teller.session', [
            'session' => $session,
        ]);
    }

    public function post(Request $request): RedirectResponse
    {
        $this->requirePermission('teller.operate');

        $session = TellerSession::query()
            ->where('user_id', $this->actor()->id)
            ->where('status', TellerSession::STATUS_OPEN)
            ->latest('opened_at')
            ->first();

        if (! $session) {
            return back()->withErrors(['teller' => 'Open a till before posting transactions.']);
        }

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'category' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank,mpesa,cheque'],
            'cheque_number' => ['required_if:method,cheque', 'nullable', 'string', 'max:40'],
            'bank_name' => ['required_if:method,cheque', 'nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:120'],
            'drawer_name' => ['nullable', 'string', 'max:120'],
            'cheque_date' => ['required_if:method,cheque', 'nullable', 'date'],
            'payee_name' => ['nullable', 'string', 'max:120'],
            'waive_charges' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:120'],
            'narration' => ['nullable', 'string', 'max:500'],
            'savings_account_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
            'contribution_id' => ['nullable', 'integer'],
            'investment_product_id' => ['nullable', 'integer'],
            'investment_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $data['amount'] = Money::toMinor($data['amount']);

        try {
            $entry = $this->teller->post($session, $data, $this->actor());
        } catch (BusinessRuleException $exception) {
            return back()->withInput()->withErrors(['teller' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['teller' => 'The transaction could not be posted. Please check the details and try again.']);
        }

        return redirect()
            ->route('teller.entries.receipt', $entry)
            ->with('status', 'Transaction posted · receipt '.$entry->receipt_no.'.');
    }

    public function cheques(Request $request): View
    {
        $this->requirePermission('teller.view');

        $status = (string) $request->query('status', '');

        if (! in_array($status, ['', TellerEntry::STATUS_PENDING, TellerEntry::STATUS_CLEARED, TellerEntry::STATUS_BOUNCED], true)) {
            $status = '';
        }

        $cheques = TellerEntry::query()
            ->with(['member', 'session.teller', 'clearer', 'bouncer'])
            ->where('method', TellerEntry::METHOD_CHEQUE)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'all' => TellerEntry::query()->where('method', TellerEntry::METHOD_CHEQUE)->count(),
            'pending' => TellerEntry::query()
                ->where('method', TellerEntry::METHOD_CHEQUE)
                ->where('status', TellerEntry::STATUS_PENDING)
                ->count(),
            'cleared' => TellerEntry::query()
                ->where('method', TellerEntry::METHOD_CHEQUE)
                ->where('status', TellerEntry::STATUS_CLEARED)
                ->count(),
            'bounced' => TellerEntry::query()
                ->where('method', TellerEntry::METHOD_CHEQUE)
                ->where('status', TellerEntry::STATUS_BOUNCED)
                ->count(),
        ];

        return view('teller.cheques', [
            'cheques' => $cheques,
            'counts' => $counts,
            'status' => $status,
        ]);
    }

    public function clearCheque(Request $request, TellerEntry $entry): RedirectResponse
    {
        return $this->resolveCheque($request, $entry, TellerEntry::STATUS_CLEARED);
    }

    public function bounceCheque(Request $request, TellerEntry $entry): RedirectResponse
    {
        return $this->resolveCheque($request, $entry, TellerEntry::STATUS_BOUNCED);
    }

    public function receipt(TellerEntry $entry): View
    {
        $this->requirePermission('teller.view');

        $entry->load(['member', 'session.teller', 'journalEntry', 'creator']);

        return view('teller.receipt', [
            'entry' => $entry,
            'group' => $this->group(),
        ]);
    }

    private function resolveCheque(
        Request $request,
        TellerEntry $entry,
        string $outcome
    ): RedirectResponse {
        $this->requirePermission('teller.operate');

        $data = $request->validate([
            'clearance_notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $entry = $this->teller->clearCheque(
                $entry,
                $outcome,
                $this->actor(),
                $data['clearance_notes'] ?? null
            );
        } catch (BusinessRuleException $exception) {
            return back()->withErrors(['teller' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['teller' => 'The cheque could not be updated. Please try again.']);
        }

        $message = $entry->isCleared()
            ? 'Cheque cleared · receipt '.$entry->receipt_no.' posted to the ledger.'
            : 'Cheque marked as bounced · nothing was posted to the ledger.';

        return redirect()
            ->route('teller.cheques.index')
            ->with('status', $message);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function requireAnyPermission(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->context()->hasPermission($permission)) {
                return;
            }
        }

        abort(403, 'You are not authorised for this action.');
    }
}
