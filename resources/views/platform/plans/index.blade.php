@extends('layouts.platform')

@section('title', 'Plans')

@section('content')
    @php
        $intervalOptions = collect($intervals)->mapWithKeys(fn ($interval) => [$interval => ucfirst($interval)])->all();
        $activePlans = $plans->where('is_active', true)->count();
        $totalSubscriptions = $plans->sum('subscriptions_count');
        $mrr = $plans->sum(fn ($plan) => match ($plan->interval) {
            'quarterly' => intdiv($plan->price, 3) * $plan->subscriptions_count,
            'annual' => intdiv($plan->price, 12) * $plan->subscriptions_count,
            default => $plan->price * $plan->subscriptions_count,
        });
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Plans</h1>
            <p class="mt-1 text-sm text-slate-500">Pricing, member limits and bundled modules for group subscriptions.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('platform.dashboard') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="arrow-left" class="h-4 w-4" /> Overview
            </a>
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> New plan
                </summary>
                <div class="absolute right-0 z-20 mt-2 w-[min(30rem,calc(100vw-2rem))] rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    <form method="POST" action="{{ route('platform.plans.store') }}" class="space-y-3">
                        @csrf
                        <p class="text-sm font-semibold text-slate-900">Create a plan</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-input id="new-plan-name" name="name" label="Name" placeholder="e.g. Growth" required />
                            <x-input id="new-plan-slug" name="slug" label="Slug" placeholder="auto-generated" />
                            <x-input id="new-plan-price" name="price" type="number" step="0.01" min="0" label="Price (KES)" required />
                            <x-select id="new-plan-interval" name="interval" label="Billing interval" :options="$intervalOptions" required />
                            <x-input id="new-plan-trial" name="trial_days" type="number" min="0" max="365" label="Trial days" value="14" required />
                            <x-input id="new-plan-groups" name="max_groups" type="number" min="1" label="Max groups" value="1" required />
                            <x-input id="new-plan-members" name="max_members" type="number" min="1" label="Max members" value="100" required />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-700">Modules</p>
                            <div class="mt-1 grid gap-1 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
                                @foreach ($modules as $module)
                                    <label class="flex items-center gap-2 text-xs text-slate-600">
                                        <input type="checkbox" name="modules[]" value="{{ $module }}" @checked($module === 'core') class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span>{{ ucfirst($module) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" checked class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            Active and available for subscription
                        </label>
                        <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create plan</button>
                    </form>
                </div>
            </details>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Plans" :value="number_format($plans->count())" :hint="$activePlans.' active'" />
        <x-stat label="Subscriptions" :value="number_format($totalSubscriptions)" hint="Active and trialing" tone="info" />
        <x-stat label="Estimated MRR" :value="\App\Support\Money::format($mrr)" hint="Price normalised monthly" tone="brand" />
        <x-stat label="Modules" :value="number_format(count($modules))" hint="Available to bundle" tone="neutral" />
    </div>

    @if ($errors->any())
        <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-semibold">The plan could not be saved</p>
            <ul class="mt-1 list-inside list-disc space-y-0.5 text-rose-700">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plans->isEmpty())
        <x-empty title="No plans" message="Create a plan so groups can subscribe." class="mt-6" />
    @else
        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            @foreach ($plans as $plan)
                <div class="flex flex-col overflow-hidden rounded-xl border {{ $plan->is_active ? 'border-slate-200' : 'border-dashed border-slate-300' }} bg-white shadow-sm">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-slate-900">{{ $plan->name }}</h2>
                                <x-badge :status="$plan->is_active ? 'active' : 'inactive'" :label="$plan->is_active ? 'Active' : 'Inactive'" />
                            </div>
                            <p class="mt-0.5 text-xs text-slate-400">{{ $plan->slug }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-semibold text-slate-900">{{ $plan->priceLabel() }}</p>
                            <p class="text-xs text-slate-400">{{ $plan->subscriptions_count }} active subscription(s)</p>
                        </div>
                    </div>

                    <div class="grid gap-3 px-4 py-4 text-sm sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-slate-500">Max members</p>
                            <p class="font-semibold text-slate-900">{{ number_format($plan->max_members) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Max groups</p>
                            <p class="font-semibold text-slate-900">{{ number_format($plan->max_groups) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Trial</p>
                            <p class="font-semibold text-slate-900">{{ $plan->trial_days }} day(s)</p>
                        </div>
                    </div>

                    <div class="px-4 pb-4">
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($plan->modules ?? [] as $module)
                                <x-badge status="info" :label="ucfirst($module)" />
                            @empty
                                <span class="text-xs text-slate-400">No modules bundled</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                        <details class="flex-1">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                <x-icon name="cog" class="h-3.5 w-3.5" /> Edit plan
                            </summary>
                            <form method="POST" action="{{ route('platform.plans.update', $plan) }}" class="mt-3 space-y-3 rounded-lg border border-slate-200 bg-white p-3">
                                @csrf
                                @method('PUT')
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-input :id="'plan-'.$plan->id.'-name'" name="name" label="Name" :value="$plan->name" required />
                                    <x-input :id="'plan-'.$plan->id.'-slug'" name="slug" label="Slug" :value="$plan->slug" />
                                    <x-input :id="'plan-'.$plan->id.'-price'" name="price" type="number" step="0.01" min="0" label="Price (KES)" :value="$plan->price / 100" required />
                                    <x-select :id="'plan-'.$plan->id.'-interval'" name="interval" label="Billing interval" :options="$intervalOptions" :value="$plan->interval" required />
                                    <x-input :id="'plan-'.$plan->id.'-trial'" name="trial_days" type="number" min="0" max="365" label="Trial days" :value="$plan->trial_days" required />
                                    <x-input :id="'plan-'.$plan->id.'-groups'" name="max_groups" type="number" min="1" label="Max groups" :value="$plan->max_groups" required />
                                    <x-input :id="'plan-'.$plan->id.'-members'" name="max_members" type="number" min="1" label="Max members" :value="$plan->max_members" required />
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-slate-700">Modules</p>
                                    <div class="mt-1 grid gap-1 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
                                        @foreach ($modules as $module)
                                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                                <input type="checkbox" name="modules[]" value="{{ $module }}" @checked(in_array($module, $plan->modules ?? [], true)) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                <span>{{ ucfirst($module) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" @checked($plan->is_active) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    Active
                                </label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Save plan</button>
                                </div>
                            </form>
                        </details>

                        <form method="POST" action="{{ route('platform.plans.toggle', $plan) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                {{ $plan->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-card title="Billing notes" class="mt-6">
        <ul class="space-y-2 text-sm text-slate-600">
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Prices are entered in KES and stored in minor units.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> At least one active plan must remain available before a plan can be deactivated.</li>
            <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Every plan change is written to the audit log.</li>
        </ul>
    </x-card>
@endsection
