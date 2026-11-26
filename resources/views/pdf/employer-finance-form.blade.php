<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employer remittance form · {{ $loan->loan_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; line-height: 1.45; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        h2 { font-size: 10px; margin: 12px 0 5px; text-transform: uppercase; letter-spacing: .05em; color: #334155; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 8px; margin-bottom: 12px; }
        .badge { display: inline-block; padding: 2px 7px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5px 7px; border: 1px solid #dbe3ec; vertical-align: top; text-align: left; }
        th { background: #f8fafc; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; color: #475569; width: 22%; }
        .box { border: 1px dashed #94a3b8; border-radius: 6px; padding: 10px; }
        .fill { border-bottom: 1px solid #94a3b8; display: inline-block; min-width: 150px; }
        .sign-row td { border: none; padding: 16px 6px 2px; }
        .line { border-top: 1px solid #94a3b8; padding-top: 3px; font-size: 9px; color: #475569; }
        .stamp { border: 1px dashed #94a3b8; height: 62px; text-align: center; color: #94a3b8; padding-top: 24px; }
        .footer { margin-top: 12px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 8px; color: #94a3b8; }
    </style>
</head>
<body>
    @php
        $advance = data_get($loan->terms_snapshot, 'advance_application', []);
        $dash = '—';
        $fmtSalary = function ($v) use ($dash) {
            if ($v === null || $v === '') return $dash;
            return 'KES '.number_format((float) $v, 2);
        };
        $termsLabel = match ($advance['employment_terms'] ?? null) {
            'permanent' => 'Permanent',
            'contract' => 'Contract',
            'casual' => 'Casual',
            'other' => 'Other',
            default => $dash,
        };
        $disbLabel = match ($advance['disbursement_method'] ?? null) {
            'bank' => 'Bank transfer',
            'fosa' => 'FOSA account',
            'mobile_money' => 'Mobile money',
            'cash' => 'Cash',
            'other' => 'Other',
            default => $dash,
        };
    @endphp

    <div class="header">
        <table>
            <tr>
                <td style="border:none; padding:0;">
                    <h1>Loan Advance Remittance Form</h1>
                    <p class="muted" style="margin:0;">{{ $loan->group?->name ?? config('app.name') }} · Employer finance approval</p>
                </td>
                <td style="border:none; padding:0; text-align:right;">
                    <span class="badge">{{ $loan->loan_no }}</span>
                    <p class="muted" style="margin:3px 0 0;">Generated {{ now()->format('d M Y') }}</p>
                </td>
            </tr>
        </table>
    </div>

    <h2>Applicant &amp; loan details</h2>
    <table>
        <tr>
            <th>Employee name</th>
            <td>{{ $loan->member?->full_name ?? $dash }}</td>
            <th>Member / account no.</th>
            <td>{{ $loan->member?->member_no ?? $dash }}</td>
        </tr>
        <tr>
            <th>National ID</th>
            <td>{{ $loan->member?->national_id ?? $dash }}</td>
            <th>KRA PIN</th>
            <td>{{ $advance['kra_pin'] ?? $dash }}</td>
        </tr>
        <tr>
            <th>Employer</th>
            <td>{{ $loan->member?->employer ?: ($settings->officeName() ?: $dash) }}</td>
            <th>Department / station</th>
            <td>{{ $advance['department'] ?? $dash }}</td>
        </tr>
        <tr>
            <th>Job title / designation</th>
            <td>{{ $advance['job_title'] ?? ($loan->member?->occupation ?: $dash) }}</td>
            <th>Payroll / staff no.</th>
            <td>{{ $advance['payroll_number'] ?? ($loan->member?->member_no ?: $dash) }}</td>
        </tr>
        <tr>
            <th>Employment terms</th>
            <td>{{ $termsLabel }}</td>
            <th>Contract expiry</th>
            <td>{{ $advance['contract_expiry_date'] ?? $dash }}</td>
        </tr>
        <tr>
            <th>Gross monthly salary</th>
            <td>{{ $fmtSalary($advance['gross_monthly_salary'] ?? null) }}</td>
            <th>Net monthly salary</th>
            <td>{{ $fmtSalary($advance['net_monthly_salary'] ?? null) }}</td>
        </tr>
        <tr>
            <th>Phone / email</th>
            <td colspan="3">{{ $loan->member?->phone ?? $dash }} @if ($loan->member?->email) · {{ $loan->member->email }} @endif</td>
        </tr>
        <tr>
            <th>Loan product</th>
            <td>{{ $loan->product?->name ?? $dash }}</td>
            <th>Loan number</th>
            <td>{{ $loan->loan_no }}</td>
        </tr>
        <tr>
            <th>Advance requested</th>
            <td><strong>{{ \App\Support\Money::format((int) $loan->requested_amount) }}</strong></td>
            <th>Total amount due</th>
            <td><strong>{{ \App\Support\Money::format((int) ($loan->terms_snapshot['quotation']['total_repayable'] ?? $loan->requested_amount)) }}</strong></td>
        </tr>
        <tr>
            <th>Term</th>
            <td>{{ $loan->duration }} {{ $loan->duration_unit }}</td>
            <th>Repayment</th>
            <td>{{ ucfirst(str_replace('_', ' ', (string) $loan->repayment_frequency)) }}</td>
        </tr>
    </table>

    <h2>Applicant disbursement details</h2>
    <table>
        <tr>
            <th>Preferred method</th>
            <td>{{ $disbLabel }}</td>
            <th>Bank / SACCO</th>
            <td>{{ $advance['disbursement_bank'] ?? $dash }}</td>
        </tr>
        <tr>
            <th>Account name</th>
            <td>{{ $advance['disbursement_account_name'] ?? ($loan->member?->full_name ?? $dash) }}</td>
            <th>Account / mobile no.</th>
            <td>{{ $advance['disbursement_account_number'] ?? ($loan->member?->phone ?? $dash) }}</td>
        </tr>
        <tr>
            <th>Branch</th>
            <td colspan="3">{{ $advance['disbursement_branch'] ?? $dash }}</td>
        </tr>
    </table>

    <h2>Remit the loan advance to</h2>
    <table>
        <tr>
            <th>Account name</th>
            <td>{{ $settings->remitAccountName() ?: '________________________________' }}</td>
            <th>Account number</th>
            <td>{{ $settings->remitAccountNumber() ?: '________________________' }}</td>
        </tr>
        <tr>
            <th>Bank</th>
            <td>{{ $settings->remitBankName() ?: '________________________________' }}</td>
            <th>Paybill number</th>
            <td>{{ $settings->remitPaybill() ?: '________________________' }}</td>
        </tr>
    </table>

    <h2>Employer finance office undertaking</h2>
    <div class="box">
        <p style="margin:0 0 8px;">
            We confirm that the employee named above is employed by this organisation. We have reviewed the loan
            advance request and <strong>agree to remit the loan advance due</strong> to the account / paybill shown above
            by the agreed deduction schedule, or to deduct the advance from the employee's salary / terminal benefits
            and remit it to the group on the due dates.
        </p>
        <table>
            <tr>
                <td style="width:50%;"><span class="muted">Approved advance amount: </span><span class="fill">&nbsp;</span></td>
                <td style="width:50%;"><span class="muted">Reference number: </span><span class="fill">&nbsp;</span></td>
            </tr>
            <tr>
                <td><span class="muted">Decision: </span> Approved &nbsp;☐&nbsp;&nbsp; Declined &nbsp;☐</td>
                <td><span class="muted">Date of undertaking: </span><span class="fill">&nbsp;</span></td>
            </tr>
        </table>
        <table class="sign-row">
            <tr>
                <td style="width:35%;">
                    <div class="line">Officer name</div>
                </td>
                <td style="width:35%;">
                    <div class="line">Officer signature</div>
                </td>
                <td style="width:30%;">
                    <div class="stamp">Official stamp</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        {{ $settings->instructions() }}
        <br>
        Generated by {{ config('app.name') }}. After stamping, the member must upload this completed form to their portal.
        The finance office may also confirm the approval using the secure link emailed to {{ $settings->email() ?: 'the employer finance office' }}.
    </div>
</body>
</html>
