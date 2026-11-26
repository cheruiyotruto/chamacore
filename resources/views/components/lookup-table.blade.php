@props([
    'columns' => [],
    'rows' => [],
    'money' => [],
    'status' => [],
    'currency' => 'KES',
    'empty' => 'No records found.',
])

@if (count($rows) === 0)
    <x-empty :title="$empty" />
@else
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($columns as $label)
                        <th class="whitespace-nowrap px-3 py-2.5">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <tr class="hover:bg-slate-50">
                        @foreach ($columns as $key => $label)
                            @php($value = $row[$key] ?? null)
                            <td class="whitespace-nowrap px-3 py-2.5 text-slate-700">
                                @if ($value === null || $value === '')
                                    <span class="text-slate-400">—</span>
                                @elseif (in_array($key, $money, true))
                                    {{ \App\Support\Money::format((int) $value, $currency) }}
                                @elseif (in_array($key, $status, true))
                                    <x-badge :status="(string) $value" />
                                @elseif ($value instanceof \DateTimeInterface)
                                    {{ $value->format('M j, Y') }}
                                @elseif (is_bool($value))
                                    {{ $value ? 'Yes' : 'No' }}
                                @elseif (is_array($value))
                                    {{ implode(', ', $value) }}
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
