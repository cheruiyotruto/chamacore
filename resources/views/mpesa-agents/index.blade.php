@extends('layouts.app')

@section('title', 'M-Pesa agents')

@section('content')
    @php
        $memberOptions = $members->mapWithKeys(fn ($member) => [
            $member->id => $member->member_no.' · '.$member->full_name,
        ])->all();
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">M-Pesa agents and stores</h1>
        <p class="mt-1 text-sm text-slate-500">
            Register the agent number and store number that receive float. Members can select a registered store or enter one manually when buying float.
        </p>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Registered agents" :subtitle="$agents->count().' agent(s)'" :padding="false">
                @if ($agents->isEmpty())
                    <x-empty title="No agents registered yet" message="Add the first agent and store using the form on the right." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($agents as $agent)
                            <div class="px-4 py-3">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $agent->name }}</p>
                                        <p class="mt-0.5 text-xs text-slate-600">
                                            Agent <span class="font-mono font-medium text-slate-800">{{ $agent->agent_number }}</span>
                                            · Store <span class="font-mono font-medium text-slate-800">{{ $agent->store_number }}</span>
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            {{ $agent->phone }}
                                            @if ($agent->member) · {{ $agent->member->full_name }} @endif
                                            · {{ $agent->purchases_count }} purchase(s)
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-badge :status="$agent->status" :label="\App\Models\MpesaAgent::STATUSES[$agent->status] ?? ucfirst($agent->status)" />
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('mpesa-agents.toggle', $agent) }}">
                                                @csrf
                                                <button type="submit" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                                    {{ $agent->status === \App\Models\MpesaAgent::STATUS_ACTIVE ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                @if ($canManage)
                                    <details class="mt-2 text-xs">
                                        <summary class="cursor-pointer font-medium text-brand-700 hover:text-brand-800">Edit details</summary>
                                        <form method="POST" action="{{ route('mpesa-agents.update', $agent) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                            @csrf
                                            @method('PUT')
                                            <x-input name="name" label="Agent / business name" :value="$agent->name" required />
                                            <x-select name="member_id" label="Linked member (optional)" :options="$memberOptions" :value="$agent->member_id" placeholder="Not linked" />
                                            <x-input name="agent_number" label="Agent number" :value="$agent->agent_number" required inputmode="numeric" />
                                            <x-input name="store_number" label="Store number" :value="$agent->store_number" required inputmode="numeric" />
                                            <x-input name="phone" type="tel" label="Registered M-Pesa number" :value="$agent->phone" required />
                                            <div class="flex items-end">
                                                <button type="submit" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save changes</button>
                                            </div>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        @if ($canManage)
            <x-card title="Register an agent" subtitle="Float is delivered to the store number.">
                <form method="POST" action="{{ route('mpesa-agents.store') }}" class="space-y-3">
                    @csrf
                    <x-input name="name" label="Agent / business name" :value="old('name')" placeholder="e.g. Main branch agent" required />
                    <x-input name="agent_number" label="Agent number" :value="old('agent_number')" placeholder="e.g. 123456" required inputmode="numeric" />
                    <x-input name="store_number" label="Store number" :value="old('store_number')" placeholder="e.g. 8899001" required inputmode="numeric" />
                    <x-input name="phone" type="tel" label="Registered M-Pesa number" :value="old('phone')" placeholder="07xx xxx xxx" required />
                    <x-select name="member_id" label="Linked member (optional)" :options="$memberOptions" :value="old('member_id')" placeholder="Not linked" />
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="users" class="h-4 w-4" /> Register agent
                    </button>
                </form>
            </x-card>
        @endif
    </div>
@endsection
