@extends('layouts.app')

@section('title', 'Payment '.$intent->uuid)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $payload = $intent->payload ?? [];
    @endphp

    <x-page-header :title="'Payment '.$intent->uuid" :subtitle="($intent->member?->full_name ?? 'Unknown member').' · '.ucfirst(str_replace('_', ' ', $intent->purpose)).' · '.$intent->created_at?->format('d M Y H:i')">
        <a href="{{ route('payments.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All payments
        </a>
        @if ($intent->member)
            <a href="{{ route('members.show', $intent->member) }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
                <x-icon name="users" class="h-4 w-4" /> Member profile
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Amount" :value="\App\Support\Money::format($intent->amount)" :hint="ucfirst(str_replace('_', ' ', $intent->purpose))" />
        <x-stat label="Status" :value="ucfirst($intent->status)" :hint="$intent->completed_at ? 'Completed '.$intent->completed_at->format('d M Y H:i') : 'Not completed'" :tone="in_array($intent->status, ['succeeded'], true) ? 'brand' : (in_array($intent->status, ['failed', 'cancelled'], true) ? 'danger' : 'warning')" />
        <x-stat label="Provider" :value="ucfirst($intent->provider ?: 'manual')" :hint="$intent->provider_reference ?: 'No provider reference'" tone="info" />
        <x-stat label="Phone" :value="$intent->phone ?: '—'" hint="M-Pesa number used" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Payment details">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Reference</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs font-medium text-slate-800">{{ $intent->uuid }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Provider reference</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $intent->provider_reference ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Purpose</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $intent->purpose)) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd class="mt-0.5"><x-badge :status="$intent->status" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Requested</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $intent->created_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Completed</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $intent->completed_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Idempotency key</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-slate-600">{{ $intent->idempotency_key ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Member</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $intent->member?->full_name ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($intent->failure_reason)
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                        <p class="font-medium">Failure reason</p>
                        <p class="mt-0.5">{{ $intent->failure_reason }}</p>
                    </div>
                @endif
            </x-card>

            <x-card title="Request payload" subtitle="Destination details captured when the request was created.">
                @if (empty($payload))
                    <p class="text-sm text-slate-500">No additional payload was stored.</p>
                @else
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        @foreach ($payload as $key => $value)
                            <div>
                                <dt class="text-slate-500">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-800">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Ledger impact">
                <p class="text-sm text-slate-600">Successful payments post to the ledger through the destination service (wallet, savings or loan repayment).</p>
                @if ($can('accounting.view'))
                    <a href="{{ route('accounting.ledger') }}" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="book" class="h-4 w-4" /> Open ledger
                    </a>
                @endif
            </x-card>

            <x-card title="Member">
                @if ($intent->member)
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">{{ $intent->member->initials() }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $intent->member->full_name }}</p>
                            <p class="text-xs text-slate-500">{{ $intent->member->member_no }} · {{ $intent->member->phone ?: 'no phone' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-sm">
                        <span class="text-slate-500">Status</span>
                        <x-badge :status="$intent->member->status" />
                    </div>
                @else
                    <p class="text-sm text-slate-500">This payment is not linked to a member record.</p>
                @endif
            </x-card>
        </div>
    </div>
@endsection
