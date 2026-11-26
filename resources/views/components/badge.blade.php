@props(['status' => 'neutral', 'label' => null])

@php
    $tone = match ($status) {
        'active', 'paid', 'approved', 'verified', 'sent', 'completed', 'present', 'accepted',
        'succeeded', 'reconciled', 'passed', 'disbursed', 'converted', 'for', 'credited' => 'success',
        'pending', 'applied', 'partial', 'partially_paid', 'queued', 'processing', 'under_review',
        'guarantors_pending', 'open', 'scheduled', 'trialing', 'new', 'contacted', 'unpaid' => 'warning',
        'late', 'overdue', 'failed', 'rejected', 'defaulted', 'written_off', 'absent', 'lost',
        'against', 'suspended', 'missed', 'inactive' => 'danger',
        'in_progress', 'info_requested', 'conditional', 'submitted', 'qualified', 'excused', 'debited' => 'info',
        default => 'neutral',
    };

    $classes = match ($tone) {
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        default => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $label ?? ucfirst(str_replace('_', ' ', $status)) }}
</span>
