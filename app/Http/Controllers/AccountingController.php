<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Member;
use App\Services\AuditService;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AccountingController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('accounting.view');

        $from = $request->filled('from') ? CarbonImmutable::parse($request->date('from')) : now()->startOfYear();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->date('to')) : now();

        return view('accounting.index', [
            'accounts' => LedgerAccount::orderBy('code')->get(),
            'bankAccounts' => BankAccount::with('ledgerAccount')->orderBy('name')->get(),
            'trialBalance' => $this->ledger->trialBalance($this->group(), $from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function journals(Request $request): View
    {
        $this->requirePermission('accounting.view');

        $entries = JournalEntry::query()
            ->with(['postedBy'])
            ->withCount('lines')
            ->when($request->filled('from'), fn ($query) => $query->whereDate('entry_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('entry_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($inner) => $inner
                    ->where('entry_no', 'like', $search)
                    ->orWhere('description', 'like', $search));
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('accounting.journals', ['entries' => $entries]);
    }

    public function showJournal(JournalEntry $entry): View
    {
        $this->requirePermission('accounting.view');

        $entry->load(['lines.account', 'lines.member', 'postedBy', 'reversalOf']);

        return view('accounting.journal-show', ['entry' => $entry]);
    }

    public function createJournal(): View
    {
        $this->requirePermission('accounting.journal');

        return view('accounting.journal-create', [
            'accounts' => LedgerAccount::where('is_active', true)->orderBy('code')->get(),
            'members' => Member::query()->active()->orderBy('first_name')->get(),
        ]);
    }

    public function storeJournal(Request $request): RedirectResponse
    {
        $this->requirePermission('accounting.journal');

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.code' => ['required', 'string', 'max:20'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.member_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        $lines = [];

        foreach ($data['lines'] as $index => $line) {
            $debit = (int) round(((float) ($line['debit'] ?? 0)) * 100);
            $credit = (int) round(((float) ($line['credit'] ?? 0)) * 100);

            if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
                throw ValidationException::withMessages([
                    "lines.$index.debit" => 'Enter either a debit or a credit for each line.',
                ]);
            }

            if (! LedgerAccount::where('code', $line['code'])->exists()) {
                throw ValidationException::withMessages([
                    "lines.$index.code" => 'The selected account does not exist.',
                ]);
            }

            $lines[] = [
                'code' => $line['code'],
                'debit' => $debit,
                'credit' => $credit,
                'member_id' => $line['member_id'] ?? null,
                'description' => $line['description'] ?? null,
            ];
        }

        try {
            $entry = $this->ledger->post(
                $this->group(),
                $data['description'],
                $lines,
                $data['entry_date']
            );
        } catch (Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journals.show', $entry)->with('status', 'Journal posted.');
    }

    public function reverseJournal(Request $request, JournalEntry $entry): RedirectResponse
    {
        $this->requirePermission('accounting.journal');

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $reversal = $this->ledger->reverse($this->group(), $entry, $data['reason']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journals.show', $reversal)
            ->with('status', 'Journal reversed. The reversal has been posted.');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->requirePermission('accounting.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if (LedgerAccount::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'This account code is already in use.']);
        }

        $account = LedgerAccount::create([
            'group_id' => $this->group()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => $data['normal_balance'],
            'is_system' => false,
            'is_active' => true,
            'description' => $data['description'] ?? null,
        ]);

        AuditService::record('ledger_account.created', $account, null, $account->only(['code', 'name', 'type']));

        return back()->with('status', 'Account '.$account->code.' created.');
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $this->requirePermission('accounting.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:120'],
            'account_no' => ['required', 'string', 'max:60'],
            'branch' => ['nullable', 'string', 'max:120'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $ledgerAccount = $this->ledger->account($this->group(), LedgerService::BANK);
        $opening = (int) round(((float) ($data['opening_balance'] ?? 0)) * 100);

        $account = BankAccount::create([
            'group_id' => $this->group()->id,
            'ledger_account_id' => $ledgerAccount->id,
            'name' => $data['name'],
            'bank_name' => $data['bank_name'],
            'account_no' => $data['account_no'],
            'branch' => $data['branch'] ?? null,
            'opening_balance' => $opening,
            'current_balance' => $opening,
            'is_active' => true,
        ]);

        AuditService::record('bank_account.created', $account, null, $account->only(['name', 'account_no', 'bank_name']));

        return back()->with('status', 'Bank account added.');
    }

    public function reconcileBank(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->requirePermission('accounting.manage');

        $data = $request->validate([
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $statement = (int) round(((float) $data['statement_balance']) * 100);
        $ledgerBalance = $this->ledger->accountBalance($this->group(), $bankAccount->ledgerAccount->code);
        $difference = $statement - $ledgerBalance;

        $reconciliation = BankReconciliation::create([
            'group_id' => $this->group()->id,
            'bank_account_id' => $bankAccount->id,
            'statement_date' => $data['statement_date'],
            'statement_balance' => $statement,
            'ledger_balance' => $ledgerBalance,
            'difference' => $difference,
            'status' => $difference === 0 ? 'reconciled' : 'discrepancy',
            'notes' => $data['notes'] ?? null,
            'reconciled_by' => $this->actor()->id,
            'reconciled_at' => now(),
        ]);

        $bankAccount->update(['current_balance' => $statement]);

        AuditService::record('bank.reconciled', $reconciliation, null, [
            'difference' => $difference,
            'statement_date' => $reconciliation->statement_date?->toDateString(),
        ]);

        return back()->with(
            $difference === 0 ? 'status' : 'error',
            $difference === 0
                ? 'Bank account reconciles.'
                : 'Reconciliation saved with a difference of '.number_format($difference / 100, 2).'.'
        );
    }

    public function ledger(Request $request): View
    {
        $this->requirePermission('accounting.view');

        $account = $request->filled('account')
            ? LedgerAccount::where('code', $request->string('account'))->first()
            : LedgerAccount::orderBy('code')->first();

        $lines = $account
            ? $account->lines()
                ->with(['journalEntry', 'member'])
                ->when($request->filled('from'), fn ($query) => $query->whereHas('journalEntry', fn ($q) => $q->whereDate('entry_date', '>=', $request->date('from'))))
                ->when($request->filled('to'), fn ($query) => $query->whereHas('journalEntry', fn ($q) => $q->whereDate('entry_date', '<=', $request->date('to'))))
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
            : null;

        return view('accounting.ledger', [
            'accounts' => LedgerAccount::orderBy('code')->get(),
            'account' => $account,
            'lines' => $lines,
            'balance' => $account ? $this->ledger->accountBalance($this->group(), $account->code) : 0,
        ]);
    }
}
