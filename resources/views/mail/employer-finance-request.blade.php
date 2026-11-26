<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employer finance approval</title>
</head>
<body style="margin:0;padding:24px;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;">
        <p style="margin:0 0 4px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#64748b;">Chama Core</p>
        <h1 style="margin:0 0 18px;font-size:22px;">Employer finance approval required</h1>

        <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
            {{ $loan->member?->full_name ?? 'A group member' }} has submitted a loan application
            (<strong>{{ $loan->loan_no }}</strong>) and uploaded an employer finance approval form
            for review.
        </p>

        <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 20px;">
            <tr>
                <td style="padding:8px 0;color:#64748b;">Member</td>
                <td style="padding:8px 0;text-align:right;font-weight:600;">{{ $loan->member?->full_name }} ({{ $loan->member?->member_no }})</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">Employer</td>
                <td style="padding:8px 0;text-align:right;font-weight:600;">{{ $loan->member?->employer ?: '—' }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">Loan product</td>
                <td style="padding:8px 0;text-align:right;font-weight:600;">{{ $loan->product?->name ?? '—' }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#64748b;">Requested amount</td>
                <td style="padding:8px 0;text-align:right;font-weight:600;">{{ \App\Support\Money::format($loan->requested_amount) }}</td>
            </tr>
        </table>

        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
            Please review the stamped form and confirm whether the employer finance office has approved
            this loan. The loan cannot be disbursed until this confirmation is recorded.
        </p>

        <p style="margin:0 0 24px;">
            <a href="{{ $confirmationUrl }}"
               style="display:inline-block;background:#15803d;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px;">
                Review and confirm
            </a>
        </p>

        <p style="margin:0;font-size:12px;line-height:1.6;color:#64748b;">
            This secure link expires in 30 days. If you were not expecting this message, please contact the group administrator.
        </p>
    </div>
</body>
</html>
