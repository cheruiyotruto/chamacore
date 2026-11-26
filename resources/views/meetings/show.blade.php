@extends('layouts.app')

@section('title', $meeting->title)

@section('content')
    @php
        $present = $meeting->attendances->whereIn('status', ['present', 'late'])->count();
        $absent = $meeting->attendances->where('status', 'absent')->count();
        $attendanceTotal = $meeting->attendances->count();
        $attendanceRate = $attendanceTotal > 0 ? (int) round(($present / $attendanceTotal) * 100) : 0;
        $fineOutstanding = $fines->sum(fn ($fine) => $fine->outstanding());
    @endphp

    <x-page-header :title="$meeting->title" :subtitle="strtoupper($meeting->type).' · '.($meeting->scheduled_at?->format('l, d F Y \a\t H:i') ?? 'date to be confirmed').($meeting->location ? ' · '.$meeting->location : '')">
        <a href="{{ route('meetings.export', $meeting) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="download" class="h-4 w-4" /> Collections (CSV)
        </a>
        <a href="{{ route('meetings.index') }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> All meetings
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Attendance" :value="$present.' / '.$attendanceTotal" :hint="$attendanceRate.'% present or late · '.$absent.' absent'" />
        <x-stat label="Collected" :value="\App\Support\Money::format($collected)" hint="Savings, shares, welfare and fines" tone="info" />
        <x-stat label="Outstanding fines" :value="\App\Support\Money::format($fineOutstanding)" :hint="$fines->count().' unsettled fine(s)'" :tone="$fineOutstanding > 0 ? 'warning' : 'neutral'" />
        <x-stat label="Status" :value="ucfirst($meeting->status)" :hint="$meeting->completed_at ? 'Completed '.$meeting->completed_at->format('d M Y') : 'Not yet completed'" tone="neutral" />
    </div>

    @if ($canManage && $meeting->status !== 'completed')
        <x-card title="Record attendance" subtitle="Set each member's status. Fine amounts are optional and posted as fine records." :padding="false">
            <form method="POST" action="{{ route('meetings.attendance', $meeting) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">Member</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5">Fine (cash)</th>
                                <th class="px-4 py-2.5">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($members as $member)
                                @php
                                    $record = $attendance->get($member->id);
                                    $status = old('attendance.'.$loop->index.'.status', $record?->status ?? 'present');
                                @endphp
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <input type="hidden" name="attendance[{{ $loop->index }}][member_id]" value="{{ $member->id }}">
                                        <p class="font-medium text-slate-800">{{ $member->full_name }}</p>
                                        <p class="text-xs text-slate-400">{{ $member->member_no }}</p>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <select name="attendance[{{ $loop->index }}][status]" class="w-32 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700">
                                            @foreach (['present' => 'Present', 'late' => 'Late', 'excused' => 'Excused', 'absent' => 'Absent'] as $value => $label)
                                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <input type="number" step="0.01" min="0" name="attendance[{{ $loop->index }}][fine_amount]"
                                               value="{{ old('attendance.'.$loop->index.'.fine_amount', $record ? number_format($record->fine_amount / 100, 2, '.', '') : '') }}"
                                               class="w-28 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700" placeholder="0.00">
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <input type="text" name="attendance[{{ $loop->index }}][notes]" value="{{ old('attendance.'.$loop->index.'.notes', $record?->notes) }}"
                                               class="w-full rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700" placeholder="Optional">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
                    <p class="text-xs text-slate-500">{{ $members->count() }} active member(s)</p>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save attendance</button>
                </div>
            </form>
        </x-card>
    @else
        <x-card title="Attendance" :padding="false">
            @if ($meeting->attendances->isEmpty())
                <x-empty title="Attendance not recorded" class="m-4" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">Member</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5 text-right">Fine</th>
                                <th class="px-4 py-2.5">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($meeting->attendances as $record)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-slate-800">{{ $record->member?->full_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5"><x-badge :status="$record->status" /></td>
                                    <td class="px-4 py-2.5 text-right text-slate-600">@money($record->fine_amount)</td>
                                    <td class="px-4 py-2.5 text-slate-600">{{ $record->notes ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @endif

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            @if ($canManage && $meeting->status !== 'completed')
                <x-card title="Record a collection" subtitle="Savings and welfare collections post to the ledger and member accounts.">
                    <form method="POST" action="{{ route('meetings.collections.store', $meeting) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @csrf
                        <x-select name="member_id" label="Member" :options="$members->mapWithKeys(fn ($m) => [$m->id => $m->member_no.' · '.$m->full_name])->all()" placeholder="Select member" required />
                        <x-select name="type" label="Type" :options="['savings' => 'Savings', 'share' => 'Share capital', 'contribution' => 'Contribution', 'welfare' => 'Welfare', 'fine' => 'Fine']" required />
                        <x-input name="amount" type="number" step="0.01" min="1" label="Amount" required />
                        <x-select name="savings_product_id" label="Savings product" placeholder="Not applicable" :options="$savingsProducts->mapWithKeys(fn ($p) => [$p->id => $p->name])->all()" />
                        <x-select name="welfare_fund_id" label="Welfare fund" placeholder="Not applicable" :options="$welfareFunds->mapWithKeys(fn ($f) => [$f->id => $f->name])->all()" />
                        <x-select name="fine_id" label="Fine" placeholder="Not applicable" :options="$fines->mapWithKeys(fn ($f) => [$f->id => ($f->member?->full_name ?? 'Member').' · '.\App\Support\Money::format($f->outstanding())])->all()" />
                        <x-select name="method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'mpesa' => 'M-Pesa']" required />
                        <x-input name="reference" label="Reference" placeholder="Optional" />
                        <div class="flex items-end">
                            <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Record collection</button>
                        </div>
                    </form>
                </x-card>
            @endif

            <x-card title="Collections" :subtitle="$meeting->collections->count().' record(s) · '.\App\Support\Money::format($collected)" :padding="false">
                @if ($meeting->collections->isEmpty())
                    <x-empty title="No collections" message="Collections recorded during the meeting appear here." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Member</th>
                                    <th class="px-4 py-2.5">Type</th>
                                    <th class="px-4 py-2.5">Source</th>
                                    <th class="px-4 py-2.5">Reference</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($meeting->collections as $collection)
                                    <tr>
                                        <td class="px-4 py-2.5 font-medium text-slate-800">{{ $collection->member?->full_name ?? '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$collection->type === 'fine' ? 'pending' : 'paid'" :label="ucfirst($collection->type)" /></td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $collection->product?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $collection->reference ?: '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($collection->amount)</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50">
                                <tr>
                                    <td colspan="4" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Total collected</td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($collected)</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Minutes">
                @if ($meeting->minutes)
                    <div class="whitespace-pre-line text-sm text-slate-700">{{ $meeting->minutes }}</div>
                @else
                    <p class="text-sm text-slate-500">Minutes have not been recorded yet.</p>
                @endif

                @if ($canManage && $meeting->status !== 'completed')
                    <form method="POST" action="{{ route('meetings.complete', $meeting) }}" class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                        @csrf
                        <x-textarea name="minutes" label="Meeting minutes" rows="6" :value="$meeting->minutes" required />
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            <x-icon name="check" class="h-4 w-4" /> Complete meeting
                        </button>
                    </form>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Meeting details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$meeting->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Type</dt>
                        <dd class="font-medium uppercase text-slate-800">{{ $meeting->type }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Scheduled</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $meeting->scheduled_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Location</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $meeting->location ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Completed</dt>
                        <dd class="font-medium text-slate-800">{{ $meeting->completed_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($meeting->agenda)
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Agenda</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $meeting->agenda }}</p>
                    </div>
                @endif
            </x-card>

            <x-card title="Outstanding fines" :padding="false">
                @if ($fines->isEmpty())
                    <p class="p-4 text-sm text-slate-500">No outstanding fines.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($fines as $fine)
                            <li class="px-4 py-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800">{{ $fine->member?->full_name ?? '—' }}</p>
                                    <p class="text-sm font-semibold text-slate-900">@money($fine->outstanding())</p>
                                </div>
                                <p class="text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $fine->type)) }} · due {{ $fine->due_date?->format('d M Y') ?? '—' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
