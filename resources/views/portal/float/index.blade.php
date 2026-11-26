@extends('layouts.portal')

@section('title', 'Buy M-Pesa float')

@section('content')
    @php
        $pending = $purchases->getCollection()->whereIn('status', ['pending', 'processing'])->count();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">Buy M-Pesa float</h1>
            <p class="mt-1 text-sm text-slate-500">Recharge a registered M-Pesa agent store from your wallet. Every purchase is recorded in the ledger.</p>
        </div>
        <a href="{{ route('portal.wallet.show') }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Back to wallet →</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Available balance" :value="\App\Support\Money::format($wallet->available_balance)" hint="Ready to spend" tone="info" />
        <x-stat label="Pending purchases" :value="(string) $pending" hint="Awaiting fulfilment" tone="warning" />
        <x-stat label="Example charge" :value="\App\Support\Money::format($quote['customer_fee'])" hint="On a KES {{ $packages[0]['major'] ?? 0 }} purchase" tone="neutral" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="My float purchases" :subtitle="$purchases->total().' purchase(s)'" :padding="false">
                @if ($purchases->isEmpty())
                    <x-empty title="No float purchases yet" message="Buy your first float using the form on the right." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($purchases as $purchase)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-800">
                                        @money($purchase->amount) · {{ $purchase->agent_name ?: 'M-Pesa agent' }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-600">
                                        Agent <span class="font-mono font-medium text-slate-800">{{ $purchase->agent_number ?: '—' }}</span>
                                        · Store <span class="font-mono font-medium text-slate-800">{{ $purchase->store_number ?: '—' }}</span>
                                        · {{ $purchase->phone }}
                                    </p>
                                    <p class="truncate font-mono text-xs text-slate-400">{{ $purchase->reference }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $purchase->created_at?->format('d M Y H:i') ?? '—' }}
                                        @if ($purchase->charge_amount > 0)
                                            · @money($purchase->charge_amount) charge
                                        @endif
                                    </p>
                                    @if ($purchase->failure_reason)
                                        <p class="mt-0.5 text-xs text-rose-600">{{ $purchase->failure_reason }}</p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <x-badge :status="$purchase->status" />
                                    <p class="mt-1 text-xs text-slate-400">Total @money($purchase->total_amount)</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $purchases->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Buy float" subtitle="Pick a package, then choose or enter the agent store.">
                <form method="POST" action="{{ route('portal.float.store') }}" x-data="{ amount: '{{ old('amount', $packages[0]['major'] ?? 100) }}' }" class="space-y-4">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="network" value="mpesa">

                    <div>
                        <label class="block text-sm font-medium text-slate-700">Quick packages</label>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            @foreach ($packages as $package)
                                <button type="button"
                                        @click="amount = '{{ $package['major'] }}'"
                                        class="rounded-lg border px-2 py-2 text-xs font-semibold transition"
                                        :class="amount === '{{ $package['major'] }}' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'">
                                    {{ $package['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <x-input name="amount" type="number" step="0.01" :min="$minAmount" :max="$maxAmount" label="Float amount (KES)" x-model="amount" required
                             :help="'Allowed range: KES '.$minAmount.' – KES '.$maxAmount" />

                    <div>
                        <label for="agent_id" class="block text-sm font-medium text-slate-700">Registered agent / store</label>
                        <select id="agent_id" name="agent_id" onchange="applySelectedAgent()" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                            <option value="">Enter agent details manually</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}"
                                        data-agent-name="{{ $agent->name }}"
                                        data-agent-number="{{ $agent->agent_number }}"
                                        data-store-number="{{ $agent->store_number }}"
                                        data-phone="{{ $agent->phone }}"
                                        @selected(old('agent_id') == $agent->id)>
                                    {{ $agent->label() }}
                                </option>
                            @endforeach
                        </select>
                        @if ($agents->isEmpty())
                            <p class="mt-1 text-xs text-slate-500">No agents registered yet. Enter the details manually below.</p>
                        @endif
                    </div>

                    <x-input name="agent_name" label="Agent / business name (optional)" :value="old('agent_name')" placeholder="e.g. Main branch agent" />
                    <div class="grid grid-cols-2 gap-3">
                        <x-input name="agent_number" label="Agent number" :value="old('agent_number')" placeholder="e.g. 123456" required inputmode="numeric" />
                        <x-input name="store_number" label="Store number" :value="old('store_number')" placeholder="e.g. 8899001" required inputmode="numeric" />
                    </div>
                    <x-input name="phone" type="tel" label="Registered M-Pesa number" :value="old('phone')" placeholder="07xx xxx xxx" required />
                    <x-input name="phone_confirmation" type="tel" label="Confirm M-Pesa number" placeholder="Re-enter the number" required />

                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="wallet" class="h-4 w-4" /> Buy float from wallet
                    </button>
                </form>
            </x-card>

            <x-card title="How it works">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> The amount and any charges are reserved from your wallet immediately.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Float is delivered to the agent number and store number you choose.</li>
                    @if ($providerConfigured)
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> The request is sent to the airtime provider automatically.</li>
                    @else
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> No airtime API is configured, so a treasurer will fulfil the purchase.</li>
                    @endif
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> If it fails, the full amount is refunded to your wallet automatically.</li>
                </ul>
            </x-card>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function applySelectedAgent(preserveManualInput = false) {
            const select = document.getElementById('agent_id');
            const option = select.options[select.selectedIndex];
            const manual = select.value === '';

            const values = {
                agent_name: manual ? '' : (option.dataset.agentName || ''),
                agent_number: manual ? '' : (option.dataset.agentNumber || ''),
                store_number: manual ? '' : (option.dataset.storeNumber || ''),
                phone: manual ? '' : (option.dataset.phone || ''),
                phone_confirmation: manual ? '' : (option.dataset.phone || ''),
            };

            Object.entries(values).forEach(([id, value]) => {
                const field = document.getElementById(id);
                if (!field) {
                    return;
                }
                if (!manual || !preserveManualInput) {
                    field.value = value;
                }
                field.readOnly = !manual;
            });
        }

        applySelectedAgent(true);
    </script>
@endpush
