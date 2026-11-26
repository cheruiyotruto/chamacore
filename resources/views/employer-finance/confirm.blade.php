<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Employer finance confirmation · {{ $loan->loan_no }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet">
    @include('partials.appearance', ['theme' => \App\Support\Appearance::for($loan->group)])
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #f8fafc; color: #0f172a; }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 32px 16px 64px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 1px 2px rgba(15,23,42,.05); }
        .card + .card { margin-top: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #475569; margin: 0 0 12px; }
        p { line-height: 1.55; }
        .muted { color: #64748b; }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .row:last-child { border-bottom: none; }
        .row dt { color: #64748b; }
        .row dd { margin: 0; font-weight: 600; text-align: right; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #334155; }
        input[type=text], textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 9px; padding: 9px 11px; font: inherit; font-size: 14px; }
        input[type=text]:focus, textarea:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 1px #0f766e; }
        .field { margin-bottom: 14px; }
        .choices { display: flex; gap: 12px; margin-bottom: 16px; }
        .choice { flex: 1; border: 1px solid #cbd5e1; border-radius: 9px; padding: 12px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .choice input { margin-right: 6px; }
        button { width: 100%; border: 0; border-radius: 9px; background: #0f766e; color: #fff; font: inherit; font-size: 14px; font-weight: 700; padding: 11px 16px; cursor: pointer; }
        button:hover { background: #115e59; }
        .alert { border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-error { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .pill { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: 12px; font-weight: 600; background: #f1f5f9; color: #475569; }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .brand span.logo { width: 36px; height: 36px; border-radius: 9px; background: #0f766e; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; }
        ul { margin: 8px 0 0; padding-left: 18px; font-size: 13px; color: #475569; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <span class="logo">C</span>
            <div>
                <p style="margin:0;font-weight:600;">{{ $loan->group?->name ?? config('app.name') }}</p>
                <p class="muted" style="margin:0;font-size:12px;">Employer finance confirmation</p>
            </div>
        </div>

        @if ($error ?? null)
            <div class="alert alert-error">{{ $error }}</div>
        @endif

        @if ($result === 'approved')
            <div class="alert alert-success">
                <strong>Approval recorded.</strong> The employer finance office has confirmed this loan. The member and group office have been notified and the loan can now be disbursed.
            </div>
        @elseif ($result === 'declined')
            <div class="alert alert-error">
                <strong>Declined.</strong> The employer finance office has declined this loan. The member and group office have been notified.
            </div>
        @else
            <div class="alert alert-warning">
                You are confirming a stamped employer finance form for loan <strong>{{ $loan->loan_no }}</strong>.
                This link is time-limited and unique to this loan.
            </div>
        @endif

        <div class="card">
            <h1>Employer finance review</h1>
            <p class="muted" style="margin-top:0;">Verify the stamped form against the details below before recording a decision.</p>

            <dl style="margin:16px 0 0;">
                <div class="row"><dt>Loan number</dt><dd>{{ $loan->loan_no }}</dd></div>
                <div class="row"><dt>Member</dt><dd>{{ $loan->member?->full_name ?? '—' }} ({{ $loan->member?->member_no }})</dd></div>
                <div class="row"><dt>Loan product</dt><dd>{{ $loan->product?->name ?? '—' }}</dd></div>
                <div class="row"><dt>Amount requested</dt><dd>{{ \App\Support\Money::format((int) $loan->requested_amount) }}</dd></div>
                <div class="row"><dt>Duration</dt><dd>{{ $loan->duration }} {{ $loan->duration_unit }}</dd></div>
                <div class="row"><dt>Purpose</dt><dd>{{ $loan->purpose ?: '—' }}</dd></div>
                <div class="row"><dt>Current status</dt><dd><span class="pill">{{ $loan->employerFinanceStatusLabel() }}</span></dd></div>
                <div class="row"><dt>Office</dt><dd>{{ $settings->officeName() ?: '—' }}</dd></div>
            </dl>

            @if ($loan->documents->isNotEmpty())
                <p style="margin:18px 0 4px;font-weight:600;font-size:13px;">Stamped documents on file</p>
                <ul>
                    @foreach ($loan->documents as $document)
                        <li>{{ $document->title }} — uploaded {{ $document->created_at?->format('d M Y H:i') }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($submitUrl)
            <div class="card">
                <h2>Record your decision</h2>
                <form method="POST" action="{{ $submitUrl }}">
                    @csrf

                    <div class="choices">
                        <label class="choice"><input type="radio" name="decision" value="approve" required> Approve</label>
                        <label class="choice"><input type="radio" name="decision" value="decline" required> Decline</label>
                    </div>

                    <div class="field">
                        <label for="officer_name">Your name <span style="color:#e11d48">*</span></label>
                        <input id="officer_name" type="text" name="officer_name" value="{{ old('officer_name', $settings->contactName()) }}" required maxlength="150">
                        @error('officer_name')<p style="color:#be123c;font-size:12px;margin:4px 0 0;">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="reference">Reference number</label>
                        <input id="reference" type="text" name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="Employer payroll or approval reference">
                        @error('reference')<p style="color:#be123c;font-size:12px;margin:4px 0 0;">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="notes">Comments</label>
                        <textarea id="notes" name="notes" rows="3" maxlength="1000" placeholder="Optional notes for the member and group office">{{ old('notes') }}</textarea>
                        @error('notes')<p style="color:#be123c;font-size:12px;margin:4px 0 0;">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit">Submit decision</button>
                </form>
            </div>
        @endif

        <p class="muted" style="font-size:11px;text-align:center;margin-top:20px;">
            If you did not expect this request, do not submit a decision. Contact {{ $loan->group?->name ?? config('app.name') }}.
        </p>
    </div>
</body>
</html>
