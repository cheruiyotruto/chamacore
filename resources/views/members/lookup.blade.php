@extends('layouts.app')

@section('title', 'Member lookup')

@section('content')
    @php
        $currency = $data['currency'] ?? 'KES';
    @endphp

    <x-page-header title="Member 360° lookup"
                   subtitle="Search by name, member number, ID number or any linked record to see the complete report.">
        @if ($member)
            <a href="{{ route('members.show', $member) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="users" class="h-4 w-4" /> Open member profile
            </a>
        @endif
    </x-page-header>

    <x-card title="Find a member" subtitle="Name, member number, ID number, phone, email, workstation or any linked record.">
        <form method="GET" action="{{ route('members.lookup') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <x-input name="q"
                         label="Search members"
                         :value="$term"
                         placeholder="Jane Doe, M0001, ID number, receipt or reference"
                         autocomplete="off" />
            </div>
            <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="search" class="h-4 w-4" /> Search
            </button>
            @if ($term !== '')
                <a href="{{ route('members.lookup') }}" class="inline-flex items-center justify-center px-3 py-2 text-sm font-medium text-slate-600 hover:text-slate-900">Clear</a>
            @endif
        </form>
    </x-card>

    @if ($error)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">{{ $error }}</p>
        </div>
    @endif

    @if ($suggestions->isNotEmpty())
        <x-card title="Possible matches"
                subtitle="Records that look like “{{ $term }}”. Open one to load its 360° report."
                class="mt-4"
                :padding="false">
            <div class="divide-y divide-slate-100">
                @foreach ($suggestions as $suggestion)
                    @php
                        $suggestionMember = $suggestion['member'];
                    @endphp
                    <a href="{{ route('members.lookup', ['member_no' => $suggestionMember->member_no]) }}"
                       class="block px-5 py-3 transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-900">{{ $suggestionMember->full_name }}</p>
                            <span class="text-xs font-medium text-slate-400">{{ $suggestion['score'] }}% similar</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $suggestionMember->member_no }}
                            · {{ $suggestion['label'] }}: {{ $suggestion['value'] }}
                        </p>
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif

    @if (! $member && $results->isNotEmpty())
        <x-card title="Matching members" subtitle="Open a result to load the full 360° report." :padding="false">
            <div class="divide-y divide-slate-100">
                @foreach ($results as $result)
                    @php
                        $resultMember = $result['member'];
                    @endphp
                    <a href="{{ route('members.lookup', ['member_no' => $resultMember->member_no]) }}"
                       class="block px-5 py-4 transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-700">
                                    {{ $resultMember->initials() }}
                                </span>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $resultMember->full_name }}</p>
                                    <p class="text-xs text-slate-500">{{ $resultMember->member_no }} · {{ $resultMember->role?->name ?? 'No role' }}</p>
                                </div>
                            </div>
                            <x-badge :status="$resultMember->status" />
                        </div>
                        <p class="mt-2 text-xs text-slate-500">
                            {{ $resultMember->national_id ?: 'No ID number' }}
                            · {{ $resultMember->phone ?: 'No phone' }}
                            · {{ $resultMember->workstation ?: 'No workstation' }}
                        </p>
                        @if ($result['matches'] !== [])
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($result['matches'] as $match)
                                    <span class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-600">
                                        {{ $match['label'] }}: {{ $match['value'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif

    @if (! $member)
        @if ($term === '')
            <x-empty title="Search for a member"
                     message="Type a member number above to load their complete record." />
        @endif
    @else
        @php
            $memberData = $data['identity']['member'];
            $summary = $data['summary'];
            $meta = collect($data['identity']['metadata'] ?? []);
        @endphp

        <x-card :padding="false">
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-bold text-brand-700">
                        {{ $member->initials() }}
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-900">{{ $member->full_name }}</h2>
                            <x-badge :status="$member->status" />
                            <x-badge :status="$member->kyc_status" />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ $member->member_no }} · {{ $member->role?->name ?? 'No role' }} · {{ $member->membership_category ?: 'ordinary' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Joined {{ $member->joined_at?->format('d M Y') ?? 'not yet' }}
                            @if ($member->approver) · approved by {{ $member->approver->name }} @endif
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:text-right">
                    <span class="text-slate-400">Phone</span><span class="font-medium text-slate-700">{{ $member->phone ?: '—' }}</span>
                    <span class="text-slate-400">Email</span><span class="font-medium text-slate-700">{{ $member->email ?: '—' }}</span>
                    <span class="text-slate-400">National ID</span><span class="font-medium text-slate-700">{{ $member->national_id ?: '—' }}</span>
                    <span class="text-slate-400">Account</span><span class="font-medium text-slate-700">{{ $member->user?->email_verified_at ? 'Verified' : 'Unverified' }}</span>
                </div>
            </div>
        </x-card>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @if ($can['savings'])
                <x-stat label="Savings" :value="\App\Support\Money::format($summary['savings_total'], $currency)" :hint="$summary['savings_accounts'].' account(s)'" />
            @endif
            @if ($can['loans'])
                <x-stat label="Loan balance" :value="\App\Support\Money::format($summary['loan_balance'], $currency)" :hint="$summary['active_loans'].' active loan(s)'" tone="info" />
            @endif
            @if ($can['wallet'])
                <x-stat label="Wallet" :value="\App\Support\Money::format($summary['wallet_balance'], $currency)" hint="Current wallet balance" tone="neutral" />
            @endif
            @if ($can['investments'])
                <x-stat label="Investments" :value="\App\Support\Money::format($summary['investment_value'], $currency)" hint="Principal plus accrued interest" tone="info" />
            @endif
            @if ($can['mgr'])
                <x-stat label="MGR contributed" :value="\App\Support\Money::format($summary['mgr_contributed'], $currency)" hint="Across all cycles" tone="brand" />
            @endif
            @if ($can['meetings'])
                <x-stat label="Fines due" :value="\App\Support\Money::format($summary['fine_outstanding'], $currency)" hint="Unsettled fines" tone="warning" />
            @endif
            @if ($can['documents'])
                <x-stat label="Documents" :value="(string) $summary['document_count']" hint="Files in the EDMS" tone="neutral" />
            @endif
        </div>

        <div class="space-y-8">
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Overview</h3>
                <div class="grid gap-4 lg:grid-cols-2">
                <x-card title="Membership at a glance">
                    <dl class="divide-y divide-slate-100 text-sm">
                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Member number</dt><dd class="font-medium text-slate-800">{{ $member->member_no }}</dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Status</dt><dd><x-badge :status="$member->status" /></dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">KYC</dt><dd><x-badge :status="$member->kyc_status" /></dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Role</dt><dd class="font-medium text-slate-800">{{ $member->role?->name ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Membership fee</dt><dd class="font-medium text-slate-800">@money($member->membership_fee_paid)</dd></div>
                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Bank transfer</dt><dd class="font-medium text-slate-800">{{ $member->can_bank_transfer ? 'Allowed' : 'Not allowed' }}</dd></div>
                        @if ($member->rejection_reason)
                            <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Rejection reason</dt><dd class="text-right text-rose-600">{{ $member->rejection_reason }}</dd></div>
                        @endif
                    </dl>
                </x-card>

                <x-card title="Application details">
                    @forelse ($meta as $key => $value)
                        <div class="flex justify-between gap-4 border-b border-slate-100 py-2 text-sm last:border-0">
                            <dt class="text-slate-500">
                                {{ ucwords(str_replace(['_', '.'], ' ', (string) $key)) }}
                                <span class="ml-2 font-mono text-[10px] text-slate-400">{{ $key }}</span>
                            </dt>
                            <dd class="text-right font-medium text-slate-800">
                                {{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : '') }}
                            </dd>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No additional application metadata.</p>
                    @endforelse

                    @if ($member->notes)
                        <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Staff notes</p>
                            <p class="mt-1 whitespace-pre-line">{{ $member->notes }}</p>
                        </div>
                    @endif
                </x-card>
                </div>
            </section>

            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Full profile</h3>

                @php
                    $media = $data['identity_media'];
                    $account = $data['identity']['account'];
                @endphp

                <x-card title="Contact & personal details">
                    <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Full name</dt><dd class="font-medium text-slate-800">{{ $member->full_name }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Gender</dt><dd class="font-medium text-slate-800">{{ ucfirst((string) $member->gender) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Date of birth</dt><dd class="font-medium text-slate-800">{{ $member->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-800">{{ $member->phone ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-800">{{ $member->email ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">National ID</dt><dd class="font-medium text-slate-800">{{ $member->national_id ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Occupation</dt><dd class="font-medium text-slate-800">{{ $member->occupation ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Employer</dt><dd class="font-medium text-slate-800">{{ $member->employer ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Workstation / work location</dt><dd class="font-medium text-slate-800">{{ $member->workstation ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">KRA PIN</dt><dd class="font-medium text-slate-800">{{ $data['identity']['kra_pin'] ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Membership category</dt><dd class="font-medium text-slate-800">{{ ucfirst((string) ($member->membership_category ?: 'ordinary')) }}</dd></div>
                        <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-slate-500">Employer address</dt><dd class="font-medium text-slate-800">{{ $member->employer_address ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-slate-500">Home address</dt><dd class="font-medium text-slate-800">{{ $member->address ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-slate-500">ID document path</dt><dd class="break-all text-right font-mono text-xs text-slate-600">{{ $data['identity']['id_document_path'] ?: '—' }}</dd></div>
                    </dl>
                </x-card>

                <x-card title="Account & verification" subtitle="Login and verification details linked to this membership.">
                    <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Login email</dt><dd class="break-all font-medium text-slate-800">{{ $account['email'] ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Account status</dt><dd class="font-medium text-slate-800">{{ $account['status'] ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Email verified</dt><dd class="font-medium text-slate-800">{{ $account['email_verified_at']?->format('d M Y H:i') ?? 'No' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Phone verified</dt><dd class="font-medium text-slate-800">{{ $account['phone_verified_at']?->format('d M Y H:i') ?? 'No' }}</dd></div>
                        <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-slate-500">Last login</dt><dd class="font-medium text-slate-800">{{ $account['last_login_at']?->format('d M Y H:i') ?? 'Never' }}</dd></div>
                    </dl>
                </x-card>

                @if ($can['documents'])
                    <x-card title="Identity media" subtitle="Passport photo, face video, signature and biometric files are previewed from the private EDMS. Every preview is logged.">
                        <div class="grid gap-4 lg:grid-cols-3">
                            @foreach ([$media['photo'], $media['video'], $media['signature'], $media['id_front'], $media['id_back']] as $item)
                                <div class="rounded-lg border border-slate-200 p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-sm font-semibold text-slate-800">{{ $item['label'] }}</p>
                                        @if ($item['available'])
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">Stored</span>
                                        @else
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Not captured</span>
                                        @endif
                                    </div>

                                    @if ($item['available'])
                                        <div class="mt-3 flex h-36 items-center justify-center overflow-hidden rounded-lg bg-slate-900/5">
                                            @if (str_starts_with((string) $item['mime_type'], 'image/'))
                                                <img src="{{ $item['preview_url'] }}" alt="{{ $item['label'] }}" class="max-h-36 w-auto object-contain">
                                            @elseif (str_starts_with((string) $item['mime_type'], 'video/'))
                                                <video controls preload="metadata" src="{{ $item['preview_url'] }}" class="max-h-36 w-full rounded"></video>
                                            @elseif ($item['mime_type'] === 'text/plain' || $item['mime_type'] === 'application/pdf')
                                                <iframe src="{{ $item['preview_url'] }}" title="{{ $item['label'] }}" class="h-36 w-full rounded border border-slate-200 bg-white"></iframe>
                                            @else
                                                <a href="{{ $item['preview_url'] }}" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-700 underline">Open file</a>
                                            @endif
                                        </div>
                                        <dl class="mt-3 space-y-1 text-xs text-slate-500">
                                            <div class="flex justify-between gap-2"><dt>Source</dt><dd class="font-medium text-slate-700">{{ $item['source'] }}</dd></div>
                                            <div class="flex justify-between gap-2"><dt>Status</dt><dd class="font-medium text-slate-700">{{ $item['status'] ?: '—' }}</dd></div>
                                            <div class="flex justify-between gap-2"><dt>Uploaded</dt><dd class="font-medium text-slate-700">{{ $item['uploaded_at']?->format('d M Y H:i') ?? '—' }}</dd></div>
                                            @if ($item['download_url'])
                                                <div class="pt-1"><a href="{{ $item['download_url'] }}" class="font-semibold text-brand-700 underline">Download original</a></div>
                                            @endif
                                        </dl>
                                    @else
                                        <p class="mt-3 text-xs text-slate-500">No file has been stored for this member.</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if (! empty($media['signature_meta']['method']) || ! empty($media['signature_meta']['name']))
                            <div class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                <p><span class="font-semibold">Signature method:</span> {{ $media['signature_meta']['method'] ?: 'Not recorded' }}</p>
                                @if ($media['signature_meta']['name'])
                                    <p class="mt-1"><span class="font-semibold">Typed name:</span> {{ $media['signature_meta']['name'] }}</p>
                                @endif
                                <p class="mt-1"><span class="font-semibold">Consent:</span> {{ $media['signature_meta']['consent'] ? 'Confirmed' : 'Not applicable' }}</p>
                            </div>
                        @endif
                    </x-card>

                    @if ($media['joining_documents'] !== [])
                        <x-card title="Joining documents" subtitle="Every file submitted with the application, linked directly to the private EDMS record.">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="px-3 py-2.5">Document</th>
                                            <th class="px-3 py-2.5">Status</th>
                                            <th class="px-3 py-2.5">Type</th>
                                            <th class="px-3 py-2.5">Uploaded</th>
                                            <th class="px-3 py-2.5">File</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($media['joining_documents'] as $item)
                                            <tr>
                                                <td class="px-3 py-2.5 font-medium text-slate-800">{{ $item['label'] }}</td>
                                                <td class="px-3 py-2.5"><x-badge :status="(string) ($item['status'] ?? 'missing')" /></td>
                                                <td class="px-3 py-2.5 text-slate-500">{{ $item['mime_type'] ?: '—' }}</td>
                                                <td class="px-3 py-2.5 text-slate-500">{{ $item['uploaded_at']?->format('d M Y H:i') ?? '—' }}</td>
                                                <td class="px-3 py-2.5">
                                                    @if ($item['available'])
                                                        <div class="flex flex-wrap gap-3 text-xs font-semibold text-brand-700">
                                                            <a href="{{ $item['preview_url'] }}" target="_blank" rel="noopener" class="underline">Preview</a>
                                                            @if ($item['download_url'])
                                                                <a href="{{ $item['download_url'] }}" class="underline">Download</a>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <span class="text-xs text-rose-600">Not on file</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-card>
                    @endif

                    <x-card title="Biometrics" subtitle="Individual biometric requirement and captured template.">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $media['biometrics']['required'] ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $media['biometrics']['required'] ? 'Required' : 'Optional' }}
                            </span>
                            <p class="text-sm text-slate-700">
                                @if ($media['biometrics']['required'])
                                    Required for this member
                                @else
                                    Optional for this member
                                @endif
                            </p>
                            <p class="text-sm {{ $media['biometrics']['captured'] ? 'font-semibold text-emerald-700' : 'text-slate-500' }}">
                                {{ $media['biometrics']['captured'] ? 'Capture on file' : 'Not captured' }}
                            </p>
                        </div>

                        @if ($media['biometrics']['items'] !== [])
                            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($media['biometrics']['items'] as $item)
                                    <div class="rounded-lg border border-slate-200 p-3">
                                        <p class="text-sm font-semibold text-slate-800">{{ $item['label'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $item['mime_type'] ?: 'Unknown type' }} · {{ $item['uploaded_at']?->format('d M Y H:i') ?? '—' }}</p>
                                        @if ($item['preview_url'])
                                            <div class="mt-2 flex flex-wrap gap-3 text-xs font-semibold text-brand-700">
                                                <a href="{{ $item['preview_url'] }}" target="_blank" rel="noopener" class="underline">Preview</a>
                                                @if ($item['download_url'])
                                                    <a href="{{ $item['download_url'] }}" class="underline">Download</a>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($media['biometrics']['metadata'] !== [])
                            <dl class="mt-4 divide-y divide-slate-100 border-t border-slate-100 text-xs">
                                @foreach ($media['biometrics']['metadata'] as $row)
                                    <div class="flex justify-between gap-4 py-2">
                                        <dt class="text-slate-500">{{ ucwords(str_replace('_', ' ', $row['key'])) }}</dt>
                                        <dd class="text-right font-medium text-slate-700">{{ $row['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </x-card>
                @endif

                <x-card title="Next of kin" :padding="false">
                    <x-lookup-table :columns="['name' => 'Name', 'relationship' => 'Relationship', 'phone' => 'Phone', 'email' => 'Email', 'id_number' => 'ID number']"
                                    :rows="$data['identity']['next_of_kin']"
                                    :currency="$currency"
                                    empty="No next of kin recorded." />
                </x-card>

                <x-card title="Beneficiaries" :padding="false">
                    <x-lookup-table :columns="['name' => 'Name', 'relationship' => 'Relationship', 'phone' => 'Phone', 'allocation_percent' => 'Allocation %', 'is_primary' => 'Primary']"
                                    :rows="$data['identity']['beneficiaries']"
                                    :currency="$currency"
                                    empty="No beneficiaries recorded." />
                </x-card>

                <x-card title="Invitations" :padding="false">
                    <x-lookup-table :columns="['created_at' => 'Created', 'email' => 'Email', 'phone' => 'Phone', 'status' => 'Status', 'sent_at' => 'Sent', 'accepted_at' => 'Accepted']"
                                    :rows="$data['identity']['invitations']"
                                    :status="['status']"
                                    :currency="$currency"
                                    empty="No invitations linked to this member." />
                </x-card>
            </section>

            @if ($can['savings'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Savings</h3>
                    <x-card title="Savings accounts" :padding="false">
                        <x-lookup-table :columns="['account_no' => 'Account', 'product' => 'Product', 'balance' => 'Balance', 'available_balance' => 'Available', 'pledged_amount' => 'Pledged', 'status' => 'Status', 'opened_at' => 'Opened']"
                                        :rows="$data['savings']['accounts']"
                                        :money="['balance', 'available_balance', 'pledged_amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No savings accounts." />
                    </x-card>

                    <x-card title="Recent savings transactions" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'type' => 'Type', 'direction' => 'Direction', 'amount' => 'Amount', 'balance_after' => 'Balance after', 'reference' => 'Reference', 'description' => 'Description']"
                                        :rows="$data['savings']['transactions']"
                                        :money="['amount', 'balance_after']"
                                        :status="['direction']"
                                        :currency="$currency"
                                        empty="No savings transactions." />
                    </x-card>
            </section>
            @endif

            @if ($can['loans'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Loans</h3>
                    <x-card title="Loans" :padding="false">
                        <x-lookup-table :columns="['loan_no' => 'Loan', 'product' => 'Product', 'status' => 'Status', 'principal' => 'Principal', 'outstanding' => 'Outstanding', 'maturity_date' => 'Maturity']"
                                        :rows="$data['loans']['loans']"
                                        :money="['principal', 'outstanding']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No loans." />
                    </x-card>

                    <x-card title="Repayment schedule" :padding="false">
                        <x-lookup-table :columns="['loan_no' => 'Loan', 'installment' => '#', 'due_date' => 'Due', 'total_due' => 'Due amount', 'total_paid' => 'Paid', 'status' => 'Status']"
                                        :rows="$data['loans']['schedules']"
                                        :money="['total_due', 'total_paid']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No repayment schedule." />
                    </x-card>

                    <x-card title="Loan repayments" :padding="false">
                        <x-lookup-table :columns="['paid_at' => 'Paid', 'loan_no' => 'Loan', 'amount' => 'Amount', 'method' => 'Method', 'reference' => 'Reference', 'status' => 'Status']"
                                        :rows="$data['loans']['payments']"
                                        :money="['amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No repayments." />
                    </x-card>

                    <x-card title="Guarantees given" :padding="false">
                        <x-lookup-table :columns="['loan_no' => 'Loan', 'guaranteed_amount' => 'Amount', 'status' => 'Status']"
                                        :rows="$data['loans']['guarantees']"
                                        :money="['guaranteed_amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No guarantees given." />
                    </x-card>

                    <x-card title="Loan approval history" :padding="false">
                        <x-lookup-table :columns="['loan_no' => 'Loan', 'step' => 'Step', 'role' => 'Role', 'action' => 'Action', 'comments' => 'Comments', 'actor' => 'Actor', 'acted_at' => 'Acted']"
                                        :rows="$data['loans']['approvals']"
                                        :status="['action']"
                                        :currency="$currency"
                                        empty="No approval history." />
                    </x-card>

                    <x-card title="Loan penalties" :padding="false">
                        <x-lookup-table :columns="['loan_no' => 'Loan', 'installment' => 'Installment', 'amount' => 'Amount', 'period_start' => 'From', 'period_end' => 'To', 'status' => 'Status', 'paid_at' => 'Paid']"
                                        :rows="$data['loans']['penalties']"
                                        :money="['amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No loan penalties." />
                    </x-card>

                    <x-card title="Loan restructures" :padding="false">
                        <x-lookup-table :columns="['loan_no' => 'Loan', 'effective_date' => 'Effective', 'status' => 'Status', 'reason' => 'Reason', 'old_terms' => 'Previous terms', 'new_terms' => 'New terms']"
                                        :rows="$data['loans']['restructures']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No restructures." />
                    </x-card>
            </section>
            @endif

            @if ($can['wallet'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Wallet</h3>
                    <x-card title="Wallet">
                        @if ($data['wallet']['account'])
                            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                                <div><dt class="text-slate-500">Balance</dt><dd class="text-lg font-semibold text-slate-900">@money($data['wallet']['account']['balance'], $currency)</dd></div>
                                <div><dt class="text-slate-500">Available</dt><dd class="text-lg font-semibold text-slate-900">@money($data['wallet']['account']['available_balance'], $currency)</dd></div>
                                <div><dt class="text-slate-500">Status</dt><dd class="mt-1"><x-badge :status="$data['wallet']['account']['status']" /></dd></div>
                            </dl>
                        @else
                            <x-empty title="No wallet" message="The member does not have a wallet in this group." />
                        @endif
                    </x-card>

                    <x-card title="Wallet transactions" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'type' => 'Type', 'direction' => 'Direction', 'amount' => 'Amount', 'balance_after' => 'Balance after', 'status' => 'Status', 'reference' => 'Reference', 'description' => 'Description']"
                                        :rows="$data['wallet']['transactions']"
                                        :money="['amount', 'balance_after']"
                                        :status="['direction', 'status']"
                                        :currency="$currency"
                                        empty="No wallet transactions." />
                    </x-card>
            </section>
            @endif

            @if ($can['investments'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Investments</h3>
                    <x-card title="Investments" :padding="false">
                        <x-lookup-table :columns="['reference_no' => 'Reference', 'product' => 'Product', 'status' => 'Status', 'principal' => 'Principal', 'total_value' => 'Current value', 'maturity_date' => 'Maturity']"
                                        :rows="$data['investments']['accounts']"
                                        :money="['principal', 'total_value']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No investments." />
                    </x-card>

                    <x-card title="Investment transactions" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'type' => 'Type', 'direction' => 'Direction', 'amount' => 'Amount', 'balance_after' => 'Balance after', 'reference' => 'Reference', 'description' => 'Description']"
                                        :rows="$data['investments']['transactions']"
                                        :money="['amount', 'balance_after']"
                                        :status="['direction']"
                                        :currency="$currency"
                                        empty="No investment transactions." />
                    </x-card>
            </section>
            @endif

            @if ($can['mgr'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Merry-go-round</h3>
                    <x-card title="Merry-go-round participations" :padding="false">
                        <x-lookup-table :columns="['cycle' => 'Cycle', 'position' => 'Position', 'payout_date' => 'Payout date', 'status' => 'Status', 'total_contributed' => 'Contributed', 'total_received' => 'Received']"
                                        :rows="$data['mgr']['participations']"
                                        :money="['total_contributed', 'total_received']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No MGR cycles." />
                    </x-card>

                    <x-card title="MGR contributions" :padding="false">
                        <x-lookup-table :columns="['cycle' => 'Cycle', 'due_date' => 'Due', 'paid_at' => 'Paid', 'status' => 'Status', 'amount' => 'Amount', 'penalty_amount' => 'Penalty', 'reference' => 'Reference']"
                                        :rows="$data['mgr']['contributions']"
                                        :money="['amount', 'penalty_amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No MGR contributions." />
                    </x-card>

                    <x-card title="MGR payouts" :padding="false">
                        <x-lookup-table :columns="['cycle' => 'Cycle', 'scheduled_date' => 'Scheduled', 'paid_at' => 'Paid', 'status' => 'Status', 'amount' => 'Amount', 'reference' => 'Reference']"
                                        :rows="$data['mgr']['payouts']"
                                        :money="['amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No MGR payouts." />
                    </x-card>
            </section>
            @endif

            @if ($can['payments'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Payments</h3>
                    <x-card title="Payment intents" :padding="false">
                        <x-lookup-table :columns="['created_at' => 'Created', 'purpose' => 'Purpose', 'provider' => 'Provider', 'phone' => 'Phone', 'amount' => 'Amount', 'status' => 'Status', 'provider_reference' => 'Provider ref', 'failure_reason' => 'Failure reason']"
                                        :rows="$data['payments']['intents']"
                                        :money="['amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No payment intents." />
                    </x-card>

                    <x-card title="Bills" :padding="false">
                        <x-lookup-table :columns="['bill_no' => 'Bill', 'payee' => 'Payee', 'amount' => 'Amount', 'amount_paid' => 'Paid', 'status' => 'Status', 'due_date' => 'Due']"
                                        :rows="$data['payments']['bills']"
                                        :money="['amount', 'amount_paid']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No bills." />
                    </x-card>

                    <x-card title="Bill payments" :padding="false">
                        <x-lookup-table :columns="['paid_at' => 'Paid', 'bill_no' => 'Bill', 'amount' => 'Amount', 'method' => 'Method', 'reference' => 'Reference']"
                                        :rows="$data['payments']['bill_payments']"
                                        :money="['amount']"
                                        :currency="$currency"
                                        empty="No bill payments." />
                    </x-card>

                    <x-card title="Payouts" :padding="false">
                        <x-lookup-table :columns="['paid_at' => 'Paid', 'source' => 'Source', 'amount' => 'Amount', 'net_amount' => 'Net', 'status' => 'Status', 'provider_reference' => 'Provider ref', 'failure_reason' => 'Failure reason']"
                                        :rows="$data['payments']['payouts']"
                                        :money="['amount', 'net_amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No payouts." />
                    </x-card>
            </section>
            @endif

            @if ($can['teller'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Teller entries</h3>
                    <x-card title="Teller entries" :padding="false">
                        <x-lookup-table :columns="['created_at' => 'Date', 'receipt_no' => 'Receipt', 'category' => 'Category', 'direction' => 'Direction', 'amount' => 'Amount', 'charge_amount' => 'Charge', 'method' => 'Method', 'reference' => 'Reference', 'recorded_by' => 'Recorded by', 'narration' => 'Narration']"
                                        :rows="$data['teller']['entries']"
                                        :money="['amount', 'charge_amount']"
                                        :currency="$currency"
                                        empty="No teller entries." />
                    </x-card>
            </section>
            @endif

            @if ($can['meetings'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Meetings &amp; fines</h3>
                    <x-card title="Meeting attendance" :padding="false">
                        <x-lookup-table :columns="['meeting' => 'Meeting', 'date' => 'Date', 'status' => 'Status', 'fine_amount' => 'Fine', 'notes' => 'Notes']"
                                        :rows="$data['meetings']['attendance']"
                                        :money="['fine_amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No attendance records." />
                    </x-card>

                    <x-card title="Fines" :padding="false">
                        <x-lookup-table :columns="['meeting' => 'Meeting', 'type' => 'Type', 'amount' => 'Amount', 'amount_paid' => 'Paid', 'outstanding' => 'Outstanding', 'status' => 'Status', 'due_date' => 'Due', 'reason' => 'Reason']"
                                        :rows="$data['meetings']['fines']"
                                        :money="['amount', 'amount_paid', 'outstanding']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No fines." />
                    </x-card>

                    <x-card title="Meeting collections" :padding="false">
                        <x-lookup-table :columns="['meeting' => 'Meeting', 'date' => 'Date', 'type' => 'Type', 'product' => 'Product', 'amount' => 'Amount', 'reference' => 'Reference']"
                                        :rows="$data['meetings']['collections']"
                                        :money="['amount']"
                                        :currency="$currency"
                                        empty="No meeting collections." />
                    </x-card>
            </section>
            @endif

            @if ($can['welfare'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Welfare</h3>
                    <x-card title="Welfare contributions" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'fund' => 'Fund', 'amount' => 'Amount', 'reference' => 'Reference']"
                                        :rows="$data['welfare']['contributions']"
                                        :money="['amount']"
                                        :currency="$currency"
                                        empty="No welfare contributions." />
                    </x-card>

                    <x-card title="Welfare claims" :padding="false">
                        <x-lookup-table :columns="['type' => 'Type', 'fund' => 'Fund', 'amount' => 'Amount', 'status' => 'Status', 'reason' => 'Reason', 'disbursed_at' => 'Disbursed']"
                                        :rows="$data['welfare']['claims']"
                                        :money="['amount']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No welfare claims." />
                    </x-card>
            </section>
            @endif

            @if ($can['documents'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Documents</h3>
                    <x-card title="Documents" :padding="false">
                        <x-lookup-table :columns="['title' => 'Document', 'type' => 'Type', 'status' => 'Status', 'verified_at' => 'Verified', 'mime_type' => 'Type (MIME)', 'size' => 'Size (bytes)', 'uploaded_by' => 'Uploaded by']"
                                        :rows="$data['documents']['documents']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No documents in the EDMS." />
                    </x-card>

                    @if (! empty($data['documents']['missing']))
                        <x-card title="Missing required documents">
                            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
                                @foreach ($data['documents']['missing'] as $missing)
                                    <li>{{ $missing }}</li>
                                @endforeach
                            </ul>
                        </x-card>
                    @endif
            </section>
            @endif

            @if ($can['notifications'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Notifications</h3>
                    <x-card title="Notification log" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'channel' => 'Channel', 'recipient' => 'Recipient', 'template' => 'Template', 'status' => 'Status', 'error' => 'Error']"
                                        :rows="$data['notifications']['logs']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No notification history." />
                    </x-card>

                    <x-card title="Reminder deliveries" :padding="false">
                        <x-lookup-table :columns="['sent_on' => 'Sent on', 'rule' => 'Rule']"
                                        :rows="$data['notifications']['reminders']"
                                        :currency="$currency"
                                        empty="No reminder deliveries." />
                    </x-card>
            </section>
            @endif

            @if ($can['crm'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">CRM &amp; support</h3>
                    <x-card title="Support tickets" :padding="false">
                        <x-lookup-table :columns="['subject' => 'Subject', 'category' => 'Category', 'priority' => 'Priority', 'status' => 'Status', 'last_reply' => 'Last reply', 'closed_at' => 'Closed']"
                                        :rows="$data['crm']['tickets']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No support tickets." />
                    </x-card>

                    <x-card title="Communications" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'channel' => 'Channel', 'direction' => 'Direction', 'subject' => 'Subject', 'body' => 'Message', 'status' => 'Status']"
                                        :rows="$data['crm']['communications']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No communications." />
                    </x-card>

                    <x-card title="Converted leads" :padding="false">
                        <x-lookup-table :columns="['name' => 'Lead', 'phone' => 'Phone', 'email' => 'Email', 'source' => 'Source', 'status' => 'Status', 'notes' => 'Notes']"
                                        :rows="$data['crm']['leads']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No converted leads." />
                    </x-card>
            </section>
            @endif

            @if ($can['governance'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Governance</h3>
                    <x-card title="Votes cast" :padding="false">
                        <x-lookup-table :columns="['resolution' => 'Resolution', 'vote' => 'Vote', 'voted_at' => 'Voted']"
                                        :rows="$data['governance']['votes']"
                                        :currency="$currency"
                                        empty="No votes cast." />
                    </x-card>
            </section>
            @endif

            @if ($can['accounting'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Accounting</h3>
                    <x-card title="Ledger postings" :padding="false">
                        <x-lookup-table :columns="['entry_no' => 'Entry', 'date' => 'Date', 'account' => 'Account', 'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit']"
                                        :rows="$data['accounting']['ledger']"
                                        :money="['debit', 'credit']"
                                        :currency="$currency"
                                        empty="No ledger postings." />
                    </x-card>
            </section>
            @endif

            @if ($can['reports'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Consolidated statement</h3>
                    <x-card title="Consolidated statement" subtitle="Savings, wallet and loan activity from the last two years." :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'type' => 'Type', 'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit', 'reference' => 'Reference']"
                                        :rows="$data['statement'] ?? []"
                                        :money="['debit', 'credit']"
                                        :currency="$currency"
                                        empty="No statement activity." />
                    </x-card>
            </section>
            @endif

            @if ($can['activity'])
            <section class="space-y-4">
                <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Activity &amp; audit</h3>
                    <x-card title="Login activity" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'email' => 'Email', 'ip_address' => 'IP address', 'status' => 'Result', 'failure_reason' => 'Failure reason']"
                                        :rows="$data['activity']['logins']"
                                        :status="['status']"
                                        :currency="$currency"
                                        empty="No login history." />
                    </x-card>

                    <x-card title="Audit trail" :padding="false">
                        <x-lookup-table :columns="['date' => 'Date', 'action' => 'Action', 'user' => 'User', 'subject' => 'Subject', 'ip_address' => 'IP address']"
                                        :rows="$data['activity']['audit']"
                                        :currency="$currency"
                                        empty="No audit activity." />
                    </x-card>
            </section>
            @endif
        </div>

        <p class="text-xs text-slate-400">Sections shown are limited to the areas your role is permitted to view.</p>
    @endif
@endsection
