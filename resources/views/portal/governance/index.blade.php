@extends('layouts.portal')

@section('title', 'Governance')

@section('content')
    @php
        $tabs = ['policies' => 'Policies', 'resolutions' => 'Resolutions'];
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">Governance</h1>
        <p class="mt-1 text-sm text-slate-500">Read the group's policies and vote on open resolutions.</p>
    </div>

    <div x-data="{ tab: 'resolutions' }" class="space-y-5">
        <div class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
            @foreach ($tabs as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                        class="rounded-lg px-3.5 py-2 text-sm font-semibold transition">
                    {{ $label }}
                    <span class="ml-1 text-xs opacity-75">{{ $key === 'policies' ? $policies->total() : $resolutions->total() }}</span>
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'resolutions'" x-cloak class="space-y-4">
            @if ($resolutions->isEmpty())
                <x-empty title="No resolutions" message="Resolutions raised in group meetings will appear here." />
            @else
                @foreach ($resolutions as $resolution)
                    @php
                        $myVote = $myVotes->get($resolution->id);
                        $totalVotes = (int) $resolution->votes_for + (int) $resolution->votes_against + (int) $resolution->votes_abstain;
                        $canVote = $resolution->status === 'open'
                            && (! $resolution->voting_opens_at || $resolution->voting_opens_at->isPast())
                            && (! $resolution->voting_closes_at || $resolution->voting_closes_at->isFuture())
                            && ! $myVote;
                    @endphp

                    <x-card>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-slate-900">{{ $resolution->title }}</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $resolution->description }}</p>
                                <p class="mt-2 text-xs text-slate-400">
                                    @if ($resolution->meeting)
                                        From {{ $resolution->meeting->title }} ·
                                    @endif
                                    @if ($resolution->voting_opens_at)
                                        Opens {{ $resolution->voting_opens_at->format('d M Y H:i') }}
                                    @endif
                                    @if ($resolution->voting_closes_at)
                                        · Closes {{ $resolution->voting_closes_at->format('d M Y H:i') }}
                                    @endif
                                    @if ($resolution->passed_at)
                                        · Passed {{ $resolution->passed_at->format('d M Y') }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge :status="$resolution->status" />
                                @if ($myVote)
                                    <x-badge :status="$myVote->vote" :label="'You voted '.$myVote->vote" />
                                @endif
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="flex h-2 overflow-hidden rounded-full bg-slate-100">
                                @if ($totalVotes > 0)
                                    <span class="bg-emerald-500" style="width: {{ round($resolution->votes_for / $totalVotes * 100) }}%"></span>
                                    <span class="bg-rose-500" style="width: {{ round($resolution->votes_against / $totalVotes * 100) }}%"></span>
                                    <span class="bg-slate-300" style="width: {{ round($resolution->votes_abstain / $totalVotes * 100) }}%"></span>
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                                <span class="font-medium text-emerald-700">{{ $resolution->votes_for }} for</span>
                                <span class="font-medium text-rose-700">{{ $resolution->votes_against }} against</span>
                                <span class="font-medium text-slate-500">{{ $resolution->votes_abstain }} abstain</span>
                                <span class="text-slate-400">{{ $totalVotes }} vote(s) cast</span>
                            </div>
                        </div>

                        @if ($canVote)
                            <form method="POST" action="{{ route('portal.governance.vote', $resolution) }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-4">
                                @csrf
                                <label class="sr-only" for="vote-{{ $resolution->id }}">Your vote</label>
                                <select id="vote-{{ $resolution->id }}" name="vote" required class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                    <option value="">Choose your vote</option>
                                    <option value="for">For</option>
                                    <option value="against">Against</option>
                                    <option value="abstain">Abstain</option>
                                </select>
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                    <x-icon name="check" class="h-4 w-4" /> Cast vote
                                </button>
                            </form>
                        @elseif (! $myVote && $resolution->status === 'open')
                            <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">Voting is not open right now.</p>
                        @elseif ($myVote)
                            <p class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700">Your vote has been recorded. Votes cannot be changed.</p>
                        @endif
                    </x-card>
                @endforeach

                <div>{{ $resolutions->links() }}</div>
            @endif
        </div>

        <div x-show="tab === 'policies'" x-cloak class="space-y-4">
            @if ($policies->isEmpty())
                <x-empty title="No policies" message="The group's constitution and policies will appear here." />
            @else
                @foreach ($policies as $policy)
                    <x-card>
                        <details class="group">
                            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900">{{ $policy->title }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ ucfirst(str_replace('_', ' ', $policy->type)) }}
                                        · Version {{ $policy->version }}
                                        · Effective {{ $policy->effective_date?->format('d M Y') ?? '—' }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-badge :status="$policy->status" />
                                    <x-icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                                </div>
                            </summary>
                            <div class="mt-4 whitespace-pre-line border-t border-slate-100 pt-4 text-sm text-slate-700">{{ $policy->content }}</div>
                        </details>
                    </x-card>
                @endforeach

                <div>{{ $policies->links() }}</div>
            @endif
        </div>
    </div>
@endsection
