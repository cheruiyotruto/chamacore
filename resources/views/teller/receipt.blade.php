<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $entry->receipt_no }} · {{ $group->name }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 32px 16px;
            background: #f1f5f9;
            color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
        .receipt {
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }
        .header { text-align: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 18px; }
        .header h1 { margin: 0; font-size: 20px; letter-spacing: -0.01em; }
        .header p { margin: 4px 0 0; color: #64748b; font-size: 12px; }
        .title { margin: 18px 0 2px; text-align: center; font-size: 12px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #475569; }
        .receipt-no { text-align: center; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px; font-weight: 700; }
        .badge { display: inline-block; margin-top: 8px; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .badge.in { background: #ecfdf5; color: #047857; }
        .badge.out { background: #fff1f2; color: #be123c; }
        .badge.pending { background: #fffbeb; color: #b45309; }
        .badge.cleared { background: #ecfdf5; color: #047857; }
        .badge.bounced { background: #fff1f2; color: #be123c; }
        .amount { margin: 20px 0; padding: 18px; border-radius: 12px; background: #f8fafc; text-align: center; }
        .amount span { display: block; color: #64748b; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; }
        .amount strong { display: block; margin-top: 4px; font-size: 30px; letter-spacing: -0.02em; }
        .rows { border-top: 1px solid #e2e8f0; }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid #f1f5f9; }
        .row dt { color: #64748b; }
        .row dd { margin: 0; text-align: right; font-weight: 600; }
        .note { margin-top: 16px; padding: 12px; border-radius: 10px; background: #f8fafc; color: #475569; font-size: 12px; }
        .signatures { display: flex; gap: 24px; margin-top: 34px; }
        .signatures div { flex: 1; border-top: 1px solid #94a3b8; padding-top: 6px; color: #64748b; font-size: 11px; }
        .footer { margin-top: 26px; text-align: center; color: #94a3b8; font-size: 11px; }
        .actions { display: flex; justify-content: center; gap: 10px; margin-top: 22px; }
        .actions button, .actions a {
            display: inline-flex; align-items: center; gap: 6px;
            border-radius: 9px; padding: 9px 14px; font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
        }
        .actions button { border: 0; background: #2563eb; color: #fff; }
        .actions a { border: 1px solid #cbd5e1; background: #fff; color: #334155; }
        @media print {
            body { padding: 0; background: #fff; }
            .receipt { max-width: none; border: 0; box-shadow: none; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    @php
        $charge = (int) ($entry->charge_amount ?? 0);
        $waived = (int) ($entry->waived_charge_amount ?? 0);
        $total = $entry->direction === 'in'
            ? (int) $entry->amount + $charge
            : (int) $entry->amount - $charge;
        $chequeDetails = (array) ($entry->cheque_details ?? []);
        $amountLabel = $entry->isCheque() && $entry->isPending()
            ? 'Cheque held pending clearance'
            : ($entry->isCheque() && $entry->isBounced() ? 'Cheque bounced' : ($entry->direction === 'in' ? 'Total received' : 'Total paid'));
    @endphp

    <main class="receipt">
        <div class="header">
            <h1>{{ $group->name }}</h1>
            <p>Teller receipt · {{ $entry->created_at?->format('d M Y H:i') ?? now()->format('d M Y H:i') }}</p>
        </div>

        <p class="title">{{ $entry->directionLabel() }} · {{ $entry->categoryLabel() }}</p>
        <p class="receipt-no">{{ $entry->receipt_no }}</p>
        <div style="text-align: center;">
            <span class="badge {{ $entry->direction === 'in' ? 'in' : 'out' }}">
                {{ $entry->direction === 'in' ? 'Received from member' : 'Paid to member' }}
            </span>
            @if ($entry->isCheque())
                <span class="badge {{ $entry->status }}">{{ $entry->statusLabel() }}</span>
            @endif
        </div>

        <div class="amount">
            <span>{{ $amountLabel }}</span>
            <strong>{{ \App\Support\Money::format($total) }}</strong>
        </div>

        <dl class="rows" style="margin: 0;">
            @if ($charge > 0)
                <div class="row">
                    <dt>{{ $entry->direction === 'in' ? 'Amount deposited' : 'Amount requested' }}</dt>
                    <dd>{{ \App\Support\Money::format($entry->amount) }}</dd>
                </div>
                @foreach (($entry->charge_breakdown ?? []) as $line)
                    @if (($line['amount'] ?? 0) > 0)
                        <div class="row">
                            <dt>{{ $line['name'] }}</dt>
                            <dd>{{ \App\Support\Money::format((int) $line['amount']) }}</dd>
                        </div>
                    @endif
                @endforeach
                @if ($waived > 0)
                    <div class="row">
                        <dt>Optional charges waived</dt>
                        <dd>{{ \App\Support\Money::format($waived) }}</dd>
                    </div>
                @endif
            @endif
            <div class="row">
                <dt>Member</dt>
                <dd>{{ $entry->member?->full_name ?? '—' }}</dd>
            </div>
            <div class="row">
                <dt>Member number</dt>
                <dd>{{ $entry->member?->member_no ?? '—' }}</dd>
            </div>
            <div class="row">
                <dt>Payment method</dt>
                <dd style="text-transform: capitalize;">{{ $entry->method }}</dd>
            </div>
            @if ($entry->isCheque())
                <div class="row">
                    <dt>Cheque number</dt>
                    <dd>{{ $chequeDetails['cheque_number'] ?? '—' }}</dd>
                </div>
                <div class="row">
                    <dt>Bank</dt>
                    <dd>{{ $chequeDetails['bank_name'] ?? '—' }}{{ isset($chequeDetails['branch']) ? ' · '.$chequeDetails['branch'] : '' }}</dd>
                </div>
                <div class="row">
                    <dt>Cheque date</dt>
                    <dd>{{ $chequeDetails['cheque_date'] ?? '—' }}</dd>
                </div>
                @if (isset($chequeDetails['drawer_name']))
                    <div class="row">
                        <dt>Drawer</dt>
                        <dd>{{ $chequeDetails['drawer_name'] }}</dd>
                    </div>
                @endif
                @if (isset($chequeDetails['payee_name']))
                    <div class="row">
                        <dt>Payee</dt>
                        <dd>{{ $chequeDetails['payee_name'] }}</dd>
                    </div>
                @endif
                @if ($entry->isCleared())
                    <div class="row">
                        <dt>Cleared</dt>
                        <dd>{{ $entry->cleared_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                @endif
                @if ($entry->isBounced())
                    <div class="row">
                        <dt>Bounced</dt>
                        <dd>{{ $entry->bounced_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                @endif
                @if ($entry->clearance_notes)
                    <div class="row">
                        <dt>Clearance notes</dt>
                        <dd>{{ $entry->clearance_notes }}</dd>
                    </div>
                @endif
            @endif
            <div class="row">
                <dt>Reference</dt>
                <dd>{{ $entry->reference ?: '—' }}</dd>
            </div>
            <div class="row">
                <dt>Teller</dt>
                <dd>{{ $entry->session?->teller?->name ?? '—' }}</dd>
            </div>
            <div class="row">
                <dt>Till session</dt>
                <dd>#{{ $entry->teller_session_id }}</dd>
            </div>
            @if ($entry->journalEntry)
                <div class="row">
                    <dt>Journal entry</dt>
                    <dd>{{ $entry->journalEntry->entry_no }}</dd>
                </div>
            @endif
            @if ($entry->chargeJournalEntry)
                <div class="row">
                    <dt>Charge journal</dt>
                    <dd>{{ $entry->chargeJournalEntry->entry_no }}</dd>
                </div>
            @endif
        </dl>

        @if ($entry->narration)
            <div class="note">{{ $entry->narration }}</div>
        @endif

        @if ($entry->isCheque() && ! $entry->isCleared())
            <div class="note">
                @if ($entry->isPending())
                    This cheque is held pending clearance. No ledger entry has been posted; the deposit is posted only when the cheque is marked cleared.
                @else
                    This cheque bounced. No ledger entry was posted for this deposit.
                @endif
            </div>
        @endif

        <div class="signatures">
            <div>Teller signature</div>
            <div>Member signature</div>
        </div>

        <p class="footer">
            Printed {{ now()->format('d M Y H:i') }} · This receipt is a computer-generated record of the transaction.
        </p>
    </main>

    <div class="actions">
        <button type="button" onclick="window.print()">Print receipt</button>
        @if ($entry->isCheque() && $entry->isPending())
            <a href="{{ route('teller.cheques.index') }}">Clear cheque</a>
        @endif
        <a href="{{ route('teller.index') }}">Back to teller desk</a>
        <a href="{{ route('teller.sessions.show', $entry->teller_session_id) }}">Till summary</a>
    </div>
</body>
</html>
