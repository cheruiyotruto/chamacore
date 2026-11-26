@extends('layouts.portal')

@section('title', 'Invite a member')

@section('content')
    @php
        $pendingCount = $invitations->getCollection()->where('status', 'pending')->count();
        $acceptedCount = $invitations->getCollection()->where('status', 'accepted')->count();
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">Invite a member</h1>
        <p class="mt-1 text-sm text-slate-500">Send a join link by email, SMS or WhatsApp. The invitee completes the full joining form and their details are stored in the group EDMS.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Invitations" :value="number_format($invitations->total())" hint="Created by you" />
        <x-stat label="Pending" :value="number_format($pendingCount)" hint="On this page" tone="warning" />
        <x-stat label="Accepted" :value="number_format($acceptedCount)" hint="On this page" tone="brand" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="My invitations" subtitle="Share the join link directly if a message did not arrive." :padding="false">
                @if ($invitations->isEmpty())
                    <x-empty title="No invitations yet" message="Invite your first member using the form." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($invitations as $invitation)
                            <div class="px-4 py-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900">
                                            {{ $invitation->recipientLabel() }}
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            Created {{ $invitation->created_at?->format('d M Y H:i') ?? '—' }}
                                            @if ($invitation->expires_at)
                                                · Expires {{ $invitation->expires_at->format('d M Y') }}
                                            @endif
                                            @if ($invitation->acceptedMember)
                                                · Joined as {{ $invitation->acceptedMember->member_no }}
                                            @endif
                                        </p>
                                        <p class="mt-1 flex flex-wrap gap-1.5 text-[11px] text-slate-500">
                                            @foreach ((array) $invitation->channels as $channel)
                                                <span class="rounded bg-slate-100 px-1.5 py-0.5">{{ $channels[$channel] ?? $channel }}</span>
                                            @endforeach
                                            @if ($invitation->requiresBiometric())
                                                <span class="rounded bg-brand-50 px-1.5 py-0.5 font-semibold text-brand-700">Biometric required</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-badge :status="$invitation->effectiveStatus()" />

                                        @if ($invitation->canBeUsed())
                                            <form method="POST" action="{{ route('portal.invitations.resend', $invitation) }}">
                                                @csrf
                                                <button type="submit" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Resend</button>
                                            </form>
                                            <form method="POST" action="{{ route('portal.invitations.revoke', $invitation) }}">
                                                @csrf
                                                <button type="submit" class="rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">Revoke</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center gap-2">
                                    <input type="text" readonly value="{{ $invitation->joinUrl() }}" data-join-link
                                           class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600">
                                    <button type="button" data-copy-link class="shrink-0 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Copy</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $invitations->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="New invitation">
                <form method="POST" action="{{ route('portal.invitations.store') }}" class="space-y-3">
                    @csrf
                    <x-input name="email" type="email" label="Email address" placeholder="name@example.com" help="Required for email invitations." />
                    <x-input name="phone" type="tel" label="Phone number" placeholder="+2547…" help="Required for SMS and WhatsApp invites." />
                    <x-textarea name="note" label="Personal note (optional)" rows="2" placeholder="e.g. Welcome to our chama!" />

                    @if ($canRequireBiometric)
                        <label class="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <input type="checkbox" name="require_biometric" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="font-medium">Require biometric capture from this applicant</span>
                                <span class="mt-0.5 block text-xs text-slate-500">Optional by default. When ticked, this join link cannot be submitted without a biometric file.</span>
                            </span>
                        </label>
                    @endif

                    <div>
                        <p class="text-sm font-medium text-slate-700">Send via</p>
                        <div class="mt-2 space-y-1.5">
                            @foreach ($channels as $value => $label)
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" name="channels[]" value="{{ $value }}" @checked($value === 'email') class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <x-select name="expires_in" label="Link expires in" :options="[7 => '7 days', 14 => '14 days', 30 => '30 days', 90 => '90 days']" :value="14" />

                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="users" class="h-4 w-4" /> Send invitation
                    </button>
                </form>
            </x-card>

            @if ($showProvidedPoints && $providedPoints !== [])
                <x-card title="What the invitee provides">
                    <ul class="space-y-2 text-sm text-slate-600">
                        @foreach ($providedPoints as $point)
                            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> {{ $point }}</li>
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-copy-link]').forEach((button) => {
            button.addEventListener('click', async () => {
                const input = button.closest('div')?.querySelector('[data-join-link]');
                if (!input) return;

                try {
                    await navigator.clipboard.writeText(input.value);
                    const original = button.textContent;
                    button.textContent = 'Copied';
                    setTimeout(() => { button.textContent = original; }, 1500);
                } catch (e) {
                    input.select();
                }
            });
        });
    </script>
@endpush
