@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canManage = $ctx->hasPermission('settings.manage');
        $money = fn (string $key) => isset($settings[$key]) && $settings[$key] !== null ? $settings[$key] / 100 : null;
        $months = collect(range(1, 12))->mapWithKeys(fn ($month) => [$month => \Illuminate\Support\Carbon::create()->month($month)->format('F')])->all();
        $tz = ['Africa/Nairobi' => 'Africa/Nairobi (EAT)', 'UTC' => 'UTC', 'Africa/Kampala' => 'Africa/Kampala', 'Africa/Dar_es_Salaam' => 'Africa/Dar es Salaam'];
        $theme = \App\Support\Appearance::for($group);
        $tabs = [
            'group' => 'Group profile',
            'financial' => 'Financial settings',
            'appearance' => 'Appearance',
            'joining' => 'Joining form',
            'employer-finance' => 'Employer finance',
            'guarantees' => 'Guarantee cover',
            'mgr-savings' => 'MGR savings transfer',
            'payment-charges' => 'Payment charges',
            'charges' => 'Transaction charges',
            'portal-access' => 'Member portal access',
            'roles' => 'Roles & permissions',
            'templates' => 'Notification templates',
        ];
    @endphp

    <x-page-header title="Settings" :subtitle="'Configure '.$group->name">
        @if ($canManage)
            <span class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600">
                <x-icon name="shield" class="h-4 w-4" /> Changes are audited
            </span>
        @endif
    </x-page-header>

    <div x-data="{ tab: 'group' }" class="space-y-5">
        <div class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
            @foreach ($tabs as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50'" class="rounded-lg px-3.5 py-2 text-sm font-semibold transition">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'group'" x-cloak class="space-y-5">
            <x-card title="Group profile" subtitle="Legal and contact details used across statements and receipts.">
                <form method="POST" action="{{ route('settings.group.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input name="name" label="Group name" :value="$group->name" required :disabled="! $canManage" />
                        <x-input name="registration_no" label="Registration number" :value="$group->registration_no" :disabled="! $canManage" />
                        <x-input name="email" type="email" label="Email" :value="$group->email" :disabled="! $canManage" />
                        <x-input name="phone" label="Phone" :value="$group->phone" :disabled="! $canManage" />
                        <x-input name="county" label="County" :value="$group->county" :disabled="! $canManage" />
                        <x-input name="sub_county" label="Sub-county" :value="$group->sub_county" :disabled="! $canManage" />
                        <x-select name="currency" label="Currency" :options="$currencyOptions" :value="$group->currency" help="Choose from currencies enabled by the platform administrator." required :disabled="! $canManage" />
                        <x-select name="timezone" label="Timezone" :options="$tz" :value="$group->timezone" required :disabled="! $canManage" />
                        <x-select name="fiscal_year_start_month" label="Fiscal year starts" :options="$months" :value="$group->fiscal_year_start_month" required :disabled="! $canManage" />
                        <x-input name="address" label="Address" :value="$group->address" :disabled="! $canManage" />
                    </div>
                    <x-textarea name="description" label="Description" rows="3" :value="$group->description" :disabled="! $canManage" />
                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save group profile</button>
                    @endif
                </form>
            </x-card>

            <x-card title="Group identifiers">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-slate-500">Slug</dt>
                        <dd class="mt-0.5 font-mono text-xs font-medium text-slate-800">{{ $group->slug }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd class="mt-0.5"><x-badge :status="$group->status" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Subscription</dt>
                        <dd class="mt-0.5"><x-badge :status="$group->subscription_status" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Created</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->created_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div x-show="tab === 'financial'" x-cloak class="space-y-5">
            <x-card title="Financial settings" subtitle="Amounts are shown in {{ $group->currency }} and stored in minor units.">
                <form method="POST" action="{{ route('settings.values.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input name="membership_fee" type="number" step="0.01" min="0" label="Membership fee" :value="$money('membership_fee')" :disabled="! $canManage" />
                        <x-input name="min_savings_for_loan" type="number" step="0.01" min="0" label="Minimum savings for a loan" :value="$money('min_savings_for_loan')" :disabled="! $canManage" />
                        <x-input name="meeting_absent_fine" type="number" step="0.01" min="0" label="Absent meeting fine" :value="$money('meeting_absent_fine')" :disabled="! $canManage" />
                        <x-input name="meeting_late_fine" type="number" step="0.01" min="0" label="Late meeting fine" :value="$money('meeting_late_fine')" :disabled="! $canManage" />
                        <x-input name="loan_late_penalty_bps" type="number" min="0" max="10000" label="Loan late penalty (bps)" :value="$settings['loan_late_penalty_bps'] ?? null" help="100 bps = 1% per period" :disabled="! $canManage" />
                        <x-input name="receipt_footer" label="Receipt footer" :value="$settings['receipt_footer'] ?? null" :disabled="! $canManage" />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                            <input type="hidden" name="require_mfa_for_staff" value="0">
                            <input type="checkbox" name="require_mfa_for_staff" value="1" @checked(! empty($settings['require_mfa_for_staff'])) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-800">Require MFA for staff</span>
                                <span class="block text-xs text-slate-500">Staff must enrol an authenticator before entering the app.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                            <input type="hidden" name="allow_member_wallet_topup" value="0">
                            <input type="checkbox" name="allow_member_wallet_topup" value="1" @checked(! empty($settings['allow_member_wallet_topup'])) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-800">Allow member wallet top-ups</span>
                                <span class="block text-xs text-slate-500">Members can fund their wallet through the portal.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                            <input type="hidden" name="allow_member_on_behalf_payments" value="0">
                            <input type="checkbox" name="allow_member_on_behalf_payments" value="1" @checked(! empty($settings['allow_member_on_behalf_payments'])) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-800">Allow paying on behalf of members</span>
                                <span class="block text-xs text-slate-500">Members can pay another member's savings, loans, bills, MGR contributions or wallet from their own wallet or M-Pesa.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                            <input type="hidden" name="allow_member_float_purchases" value="0">
                            <input type="checkbox" name="allow_member_float_purchases" value="1" @checked(! array_key_exists('allow_member_float_purchases', $settings->all()) || ! empty($settings['allow_member_float_purchases'])) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="block text-sm font-medium text-slate-800">Allow M-Pesa float purchases</span>
                                <span class="block text-xs text-slate-500">Members can buy M-Pesa float from their wallet. Purchases are reserved immediately and fulfilled by an airtime provider or a treasurer.</span>
                            </span>
                        </label>
                    </div>

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save financial settings</button>
                    @endif
                </form>
            </x-card>

            <x-card title="Configured keys" subtitle="Only the keys below are editable from this screen.">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($settingKeys as $key)
                        <div class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2">
                            <span class="font-mono text-xs text-slate-600">{{ $key }}</span>
                            @if (array_key_exists($key, $settings->all()))
                                <span class="text-xs font-medium text-emerald-700">set</span>
                            @else
                                <span class="text-xs text-slate-400">default</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>

        <div x-show="tab === 'appearance'" x-cloak class="space-y-5">
            <x-card title="Appearance" subtitle="Choose the separation borders and background used across the staff dashboard and member portal.">
                <form method="POST" action="{{ route('settings.values.update') }}" class="space-y-7">
                    @csrf
                    @method('PUT')

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Separation borders</p>
                        <p class="mt-0.5 text-xs text-slate-500">Colour used for cards, panels, tables and field outlines. Slate keeps the current subtle grey.</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach (\App\Support\Appearance::BORDERS as $key => $option)
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 text-sm transition hover:bg-slate-50 has-[:checked]:border-brand-600 has-[:checked]:ring-2 has-[:checked]:ring-brand-100">
                                    <input type="radio" name="theme_border_color" value="{{ $key }}" @checked(($settings['theme_border_color'] ?? 'slate') === $key) @disabled(! $canManage) class="peer sr-only">
                                    <span class="h-5 w-5 shrink-0 rounded-full ring-2 ring-white ring-offset-1 ring-offset-slate-300" style="background-color: {{ $option['color'] }}"></span>
                                    <span class="font-medium text-slate-700 peer-checked:text-brand-700">{{ $option['label'] }}</span>
                                    <span class="ml-auto hidden peer-checked:inline-flex text-brand-600"><x-icon name="check" class="h-4 w-4" /></span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Accent colour</p>
                        <p class="mt-0.5 text-xs text-slate-500">Used for primary buttons, active navigation and highlights.</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            @foreach (\App\Support\Appearance::ACCENTS as $key => $option)
                                <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-slate-200 bg-white p-3 text-xs transition hover:bg-slate-50 has-[:checked]:border-brand-600 has-[:checked]:ring-2 has-[:checked]:ring-brand-100">
                                    <input type="radio" name="theme_accent_color" value="{{ $key }}" @checked(($settings['theme_accent_color'] ?? 'green') === $key) @disabled(! $canManage) class="peer sr-only">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full text-white ring-2 ring-white ring-offset-1 ring-offset-slate-200" style="background-color: {{ $option['scale'][600] }}"></span>
                                    <span class="font-medium text-slate-700 peer-checked:text-brand-700">{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Page background</p>
                        <p class="mt-0.5 text-xs text-slate-500">Available background colours for the workspace behind the cards.</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            @foreach (\App\Support\Appearance::BACKGROUNDS as $key => $option)
                                <label class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-slate-200 bg-white p-3 text-xs transition hover:bg-slate-50 has-[:checked]:border-brand-600 has-[:checked]:ring-2 has-[:checked]:ring-brand-100">
                                    <input type="radio" name="theme_background_color" value="{{ $key }}" @checked(($settings['theme_background_color'] ?? 'slate') === $key) @disabled(! $canManage) class="peer sr-only">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-lg ring-2 ring-white ring-offset-1 ring-offset-slate-200" style="background-color: {{ $option['color'] }}"></span>
                                    <span class="font-medium text-slate-700 peer-checked:text-brand-700">{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Preview</p>
                        <div class="mt-3 rounded-xl border-2 p-4" style="border-color: {{ $theme['border_color'] }}; background-color: {{ $theme['background_color'] }};">
                            <div class="rounded-lg border bg-white p-4 shadow-sm" style="border-color: {{ $theme['border_color'] }};">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">Sample card</p>
                                        <p class="text-xs text-slate-500">Borders and background update across the app once saved.</p>
                                    </div>
                                    <span class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white" style="background-color: {{ $theme['accent_color'] }}">Primary action</span>
                                </div>
                                <div class="mt-3 divide-y" style="border-color: {{ $theme['border_color'] }};">
                                    <p class="py-2 text-xs text-slate-600">First separated row</p>
                                    <p class="py-2 text-xs text-slate-600">Second separated row</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save appearance</button>
                    @endif
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'joining'" x-cloak class="space-y-5">
            <x-card title="Joining form" subtitle="Choose which details applicants must write and which identity media they must attach.">
                <form method="POST" action="{{ route('settings.join-requirements.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                        First name, last name and email are always required because they create the member's sign-in account.
                        Every other field below can be hidden, optional or required.
                    </div>

                    <div>
                        <x-textarea name="requirements[joining_details]"
                                    label="Joining details"
                                    :rows="4"
                                    :value="data_get($joinRequirements, 'joining_details', '')"
                                    maxlength="{{ \App\Support\JoinRequirements::JOINING_DETAILS_MAX }}"
                                    placeholder="Explain who can join, what happens after applying, and when the group will contact the applicant."
                                    help="Shown on the public application page above the form. Line breaks are preserved." />
                    </div>

                    @foreach (\App\Support\JoinRequirements::groupedFields() as $groupLabel => $fields)
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ $groupLabel }}</p>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($fields as $key => $field)
                                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                                        <p class="text-sm font-medium text-slate-800">{{ $field['label'] }}</p>

                                        @if ($field['fixed'] ?? false)
                                            <p class="mt-1 text-xs font-medium text-slate-400">Always required</p>
                                        @else
                                            <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
                                                <label class="flex items-center gap-1.5">
                                                    <input type="hidden" name="requirements[fields][{{ $key }}][enabled]" value="0">
                                                    <input type="checkbox" name="requirements[fields][{{ $key }}][enabled]" value="1" @checked(data_get($joinRequirements, 'fields.'.$key.'.enabled', true)) @disabled(! $canManage) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                    <span>Show on form</span>
                                                </label>
                                                <label class="flex items-center gap-1.5">
                                                    <input type="hidden" name="requirements[fields][{{ $key }}][required]" value="0">
                                                    <input type="checkbox" name="requirements[fields][{{ $key }}][required]" value="1" @checked(data_get($joinRequirements, 'fields.'.$key.'.required', false)) @disabled(! $canManage) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                    <span>Required</span>
                                                </label>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Identity media</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Files are stored in the EDMS under each applicant's member number, e.g. <span class="font-mono">edms/groups/1/members/M0001</span>.
                        </p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Support\JoinRequirements::MEDIA as $key => $media)
                                <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-3">
                                    <input type="hidden" name="requirements[media][{{ $key }}][enabled]" value="0">
                                    <input type="checkbox" name="requirements[media][{{ $key }}][enabled]" value="1" @checked(data_get($joinRequirements, 'media.'.$key.'.enabled', true)) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-800">Request {{ strtolower($media['label']) }}</span>
                                        <span class="block text-xs text-slate-500">
                                            {{ $key === 'passport_photo' ? 'JPG, PNG or WEBP · up to 5 MB.' : 'MP4, WEBM or MOV · up to 20 MB.' }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="mt-3 max-w-md">
                            <label for="identity_mode" class="block text-sm font-medium text-slate-700">Identity media requirement</label>
                            <select id="identity_mode" name="requirements[identity_mode]" @disabled(! $canManage) class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                @foreach (\App\Support\JoinRequirements::IDENTITY_MODES as $value => $label)
                                    <option value="{{ $value }}" @selected(($joinRequirements['identity_mode'] ?? 'either') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Choose "Not required" to collect identity media optionally without blocking the application.</p>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 pt-5">
                        <p class="text-sm font-semibold text-slate-800">Documents and signatures</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Ask applicants to attach scanned copies or sign electronically. Every upload is stored in the EDMS under the applicant's member number.
                            "Electronic signature" is the live drawn option; "Typed signature" is typed name plus consent. Enable either or both.
                            Documents marked required must be received before a membership can be approved.
                        </p>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Support\JoinRequirements::DOCUMENTS as $key => $document)
                                <div class="rounded-lg border border-slate-200 bg-white p-3">
                                    <p class="text-sm font-medium text-slate-800">{{ $document['label'] }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $document['help'] }}</p>
                                    <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
                                        <label class="flex items-center gap-1.5">
                                            <input type="hidden" name="requirements[documents][{{ $key }}][enabled]" value="0">
                                            <input type="checkbox" name="requirements[documents][{{ $key }}][enabled]" value="1" @checked(data_get($joinRequirements, 'documents.'.$key.'.enabled', false)) @disabled(! $canManage) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span>Request this</span>
                                        </label>
                                        <label class="flex items-center gap-1.5">
                                            <input type="hidden" name="requirements[documents][{{ $key }}][required]" value="0">
                                            <input type="checkbox" name="requirements[documents][{{ $key }}][required]" value="1" @checked(data_get($joinRequirements, 'documents.'.$key.'.required', false)) @disabled(! $canManage) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span>Required for approval</span>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-5" x-data="{ rows: {{ \Illuminate\Support\Js::from(array_values((array) data_get($joinRequirements, 'custom_documents', []))) }} }">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-slate-800">Other documents</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Add your own requirements, for example a bank statement, payslip or guarantor form.</p>
                                </div>
                                <button type="button" @click="rows.push({ label: '', enabled: true, required: false })" x-show="rows.length < {{ \App\Support\JoinRequirements::MAX_CUSTOM_DOCUMENTS }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @disabled(! $canManage)>
                                    Add document
                                </button>
                            </div>

                            <div class="mt-3 space-y-2">
                                <template x-for="(row, index) in rows" :key="index">
                                    <div class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:flex-row sm:items-center">
                                        <div class="min-w-0 flex-1">
                                            <label class="sr-only">Document name</label>
                                            <input type="text" x-model="row.label" :name="`requirements[custom_documents][${index}][label]`" maxlength="120" placeholder="e.g. Bank statement or payslip" @disabled(! $canManage) class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                        </div>
                                        <input type="hidden" :name="`requirements[custom_documents][${index}][enabled]`" :value="row.enabled ? 1 : 0">
                                        <label class="flex items-center gap-1.5 text-xs text-slate-600">
                                            <input type="checkbox" x-model="row.enabled" @disabled(! $canManage) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span>Enabled</span>
                                        </label>
                                        <input type="hidden" :name="`requirements[custom_documents][${index}][required]`" :value="row.required ? 1 : 0">
                                        <label class="flex items-center gap-1.5 text-xs text-slate-600">
                                            <input type="checkbox" x-model="row.required" @disabled(! $canManage) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span>Required</span>
                                        </label>
                                        <button type="button" @click="rows.splice(index, 1)" @disabled(! $canManage) class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                            Remove
                                        </button>
                                    </div>
                                </template>

                                <p x-show="rows.length === 0" class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                    No additional documents. Use "Add document" to request one.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 pt-5">
                        <p class="text-sm font-semibold text-slate-800">What members see when inviting</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Choose which points appear in the "What the invitee provides" card on the member portal's Invite a member page.
                            This only changes what members can see; it does not change what the application form collects.
                        </p>

                        <label class="mt-3 flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <input type="hidden" name="requirements[portal_points_enabled]" value="0">
                            <input type="checkbox" name="requirements[portal_points_enabled]" value="1" @checked(data_get($joinRequirements, 'portal_points_enabled', true)) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm font-medium text-slate-800">Show the card in the member portal</span>
                        </label>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach (\App\Support\JoinRequirements::PORTAL_POINTS as $key => $label)
                                <label class="flex items-start gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-600">
                                    <input type="hidden" name="requirements[portal_points][{{ $key }}]" value="0">
                                    <input type="checkbox" name="requirements[portal_points][{{ $key }}]" value="1" @checked(data_get($joinRequirements, 'portal_points.'.$key, true)) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save joining form</button>
                    @endif
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'employer-finance'" x-cloak class="space-y-5">
            <x-card title="Employer finance loan approval"
                    subtitle="Require selected members to obtain a stamped approval from their employer's finance office before a loan can be disbursed.">
                <form method="POST" action="{{ route('settings.employer-finance.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked(! empty($employerFinance['enabled'])) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-semibold text-slate-800">Enable employer finance approval</span>
                            <span class="block text-xs text-slate-500">When enabled, only the members selected below must upload a stamped employer finance form.</span>
                        </span>
                    </label>

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Finance office contact</p>
                        <p class="mt-0.5 text-xs text-slate-500">The stamped form is emailed to this address with a secure confirmation link.</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <x-input name="office_name" label="Finance office name" :value="$employerFinance['office_name'] ?? ''" placeholder="e.g. Riverside Academy finance office" :disabled="! $canManage" />
                            <x-input name="contact_name" label="Contact person" :value="$employerFinance['contact_name'] ?? ''" placeholder="e.g. Finance Officer" :disabled="! $canManage" />
                            <x-input name="email" type="email" label="Finance office email" :value="$employerFinance['email'] ?? ''" placeholder="finance@employer.co.ke" :disabled="! $canManage" />
                            <x-input name="phone" label="Finance office phone" :value="$employerFinance['phone'] ?? ''" placeholder="+254…" :disabled="! $canManage" />
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Remittance details printed on the form</p>
                        <p class="mt-0.5 text-xs text-slate-500">The finance office uses these details to remit the loan advance. Add an account number or a paybill (or both).</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <x-input name="remit_account_name" label="Account name" :value="$employerFinance['remit_account_name'] ?? ''" placeholder="e.g. Chama Core SACCO" :disabled="! $canManage" />
                            <x-input name="remit_account_number" label="Account number" :value="$employerFinance['remit_account_number'] ?? ''" placeholder="e.g. 0123456789" :disabled="! $canManage" />
                            <x-input name="remit_bank_name" label="Bank name" :value="$employerFinance['remit_bank_name'] ?? ''" placeholder="e.g. Equity Bank" :disabled="! $canManage" />
                            <x-input name="remit_paybill" label="Paybill number" :value="$employerFinance['remit_paybill'] ?? ''" placeholder="e.g. 247365" :disabled="! $canManage" />
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-slate-800">Members who must use this workflow</p>
                        <p class="mt-0.5 text-xs text-slate-500">Tick each member whose employer must stamp and approve the loan form.</p>

                        @if ($members->isEmpty())
                            <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">There are no active members yet.</p>
                        @else
                            <div class="mt-3 grid max-h-80 gap-2 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($members as $member)
                                    <label class="flex items-start gap-2 rounded-lg border border-slate-100 p-2.5 text-sm hover:bg-slate-50">
                                        <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" @checked(in_array($member->id, $employerFinance['member_ids'] ?? [], true)) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-slate-800">{{ $member->full_name }}</span>
                                            <span class="block truncate text-xs text-slate-500">{{ $member->member_no }}@if ($member->employer) · {{ $member->employer }}@endif</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <x-textarea name="instructions" label="Member instructions" rows="3" :value="$employerFinance['instructions'] ?? ''" help="Shown in the member portal above the form download and upload controls." :disabled="! $canManage" />
                    </div>

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save employer finance settings</button>
                    @endif
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'guarantees'" x-cloak class="space-y-5">
            <x-card title="Guarantee cover"
                    subtitle="Choose what each guarantee covers and how existing guarantees reduce a member's available capacity.">
                <form method="POST" action="{{ route('settings.guarantees.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                        Full obligation means the quoted principal, all scheduled interest and all configured loan fees.
                        Late penalties are not included because they are not known when the loan is applied for.
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-select name="new_guarantees"
                                  label="New guarantee requests"
                                  :options="collect($guaranteeModes)->map(fn ($mode) => $mode['label'])->all()"
                                  :value="$guaranteeSettings['new_guarantees']"
                                  help="Applied when a guarantor is added to a new loan application."
                                  :disabled="! $canManage" />
                        <x-select name="existing_commitments"
                                  label="Existing guarantee commitments"
                                  :options="collect($guaranteeModes)->map(fn ($mode) => $mode['label'])->all()"
                                  :value="$guaranteeSettings['existing_commitments']"
                                  help="Used when calculating a member's remaining borrowing or guarantee capacity."
                                  :disabled="! $canManage" />
                    </div>

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                        <input type="hidden" name="require_signature" value="0">
                        <input type="checkbox" name="require_signature" value="1" @checked($guaranteeSettings['require_signature'] ?? true) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Require a signature on acceptance</span>
                            <span class="block text-xs text-slate-500">After accepting, guarantors are taken to a separate portal step to draw their signature. It is stored in the group EDMS against the guarantee and recorded in CRM activity.</span>
                        </span>
                    </label>

                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-sm font-medium text-slate-800">Guarantee request channels</p>
                        <p class="mt-0.5 text-xs text-slate-500">Choose where guarantors receive the request and its member-portal link. Enable any or all channels.</p>
                        <input type="hidden" name="notification_channels[]" value="">
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($guaranteeChannels as $channel => $label)
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox"
                                           name="notification_channels[]"
                                           value="{{ $channel }}"
                                           @checked(in_array($channel, $guaranteeSettings['notification_channels'] ?? [], true))
                                           @disabled(! $canManage)
                                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($guaranteeModes as $mode => $details)
                            <div class="rounded-lg border border-slate-200 bg-white p-3">
                                <p class="text-sm font-semibold text-slate-800">{{ $details['label'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $details['help'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save guarantee cover settings</button>
                    @endif
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'mgr-savings'" x-cloak class="space-y-5">
            <x-card title="MGR savings transfer"
                    subtitle="Optionally move part of every merry-go-round contribution into the paying member's individual savings.">
                <form method="POST" action="{{ route('settings.mgr-savings.update') }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked($mgrSavings['enabled']) @disabled(! $canManage) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Enable automatic savings transfer</span>
                            <span class="block text-xs text-slate-500">Applies to every MGR contribution, whether paid in the member portal or posted by a teller.</span>
                        </span>
                    </label>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                        Select <strong>Deduct from contribution</strong> to split the amount the member pays (for example 500 becomes 400 to MGR and 100 to savings),
                        or <strong>Add on top</strong> to give MGR the full amount and collect the savings portion separately.
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-select name="mode"
                                  label="Transfer mode"
                                  :options="collect($mgrSavingsModes)->map(fn ($mode) => $mode['label'])->all()"
                                  :value="$mgrSavings['mode']"
                                  help="How the savings portion is taken from each contribution."
                                  :disabled="! $canManage" />
                        <x-select name="basis"
                                  label="Amount type"
                                  :options="$mgrSavingsBases"
                                  :value="$mgrSavings['basis']"
                                  help="Use a fixed amount or a percentage of each contribution."
                                  :disabled="! $canManage" />
                        <x-input name="amount" type="number" step="0.01" min="0" max="1000000"
                                 label="Fixed amount" :value="$mgrSavings['amount'] / 100"
                                 help="Used when the amount type is Fixed amount." :disabled="! $canManage" />
                        <x-input name="rate_bps" type="number" min="0" max="10000"
                                 label="Percentage (bps)" :value="$mgrSavings['rate_bps']"
                                 help="Used when the amount type is Percentage. 1000 bps = 10%." :disabled="! $canManage" />
                    </div>

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save MGR savings settings</button>
                    @endif
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'payment-charges'" x-cloak class="space-y-5">
            <x-card title="Payment charges"
                subtitle="Optional charges on bill payments, M-Pesa payments, wallet deposits and every payment. Platform defaults apply unless overridden here.">
                <form method="POST" action="{{ route('settings.payment-charges.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    @include('components.payment-charge-rules', [
                        'rules' => $paymentChargeRules,
                        'categories' => $paymentChargeCategories,
                        'modes' => $paymentChargeModes,
                        'borneBy' => $paymentChargeBorneBy,
                        'incomeAccounts' => $incomeAccounts,
                        'canManage' => $canManage,
                        'inherited' => false,
                    ])
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'charges'" x-cloak class="space-y-5">
            @include('settings.partials.charges', [
                'charges' => $charges,
                'chargeCategories' => $chargeCategories,
                'membershipCategories' => $membershipCategories,
                'incomeAccounts' => $incomeAccounts,
                'members' => $members,
                'canManage' => $canManage,
                'group' => $group,
            ])
        </div>

        <div x-show="tab === 'portal-access'" x-cloak class="space-y-5">
            <x-card title="Member portal access" subtitle="Control which dashboard sections each member can open in the member portal. Disabled sections are hidden from navigation and cannot be accessed directly.">
                <form method="POST" action="{{ route('settings.portal-access.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                        Member documents are hidden until you enable them below. The per-member checkboxes then decide who can open each section.
                        Bank transfers are also subject to the group and member payout/bank-transfer permissions.
                    </div>

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3">
                        <input type="hidden" name="member_documents_enabled" value="0">
                        <input type="checkbox"
                               name="member_documents_enabled"
                               value="1"
                               @checked($portalDocumentsEnabled)
                               @disabled(! $canManage)
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-semibold text-slate-800">Allow members to view documents in the portal</span>
                            <span class="mt-0.5 block text-xs text-slate-500">Off by default. Officials can still manage documents in the staff area.</span>
                        </span>
                    </label>

                    @if ($members->isEmpty())
                        <p class="text-sm text-slate-500">No members found.</p>
                    @else
                        <div class="overflow-x-auto rounded-lg border border-slate-200">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="sticky left-0 bg-slate-50 px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Member</th>
                                        @foreach ($portalAccessSections as $key => $section)
                                            <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 whitespace-nowrap">{{ $section['label'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($members as $member)
                                        @php
                                            $disabled = \App\Support\PortalAccess::disabledSections($member);
                                        @endphp
                                        <tr>
                                            <td class="sticky left-0 bg-white px-3 py-2.5">
                                                <input type="hidden" name="access[{{ $member->id }}][_]" value="1">
                                                <p class="font-medium text-slate-800">{{ $member->full_name }}</p>
                                                <p class="text-xs text-slate-500">{{ $member->member_no }}</p>
                                            </td>
                                            @foreach ($portalAccessSections as $key => $section)
                                                <td class="px-3 py-2.5 text-center">
                                                    <label class="inline-flex items-center justify-center gap-1.5">
                                                        <input type="checkbox"
                                                               name="access[{{ $member->id }}][enabled][]"
                                                               value="{{ $key }}"
                                                               @checked(! in_array($key, $disabled, true))
                                                               @disabled(! $canManage)
                                                               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                        <span class="sr-only">{{ $section['label'] }}</span>
                                                    </label>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($canManage)
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save portal access</button>
                    @endif
                </form>
            </x-card>
        </div>

        <div x-show="tab === 'roles'" x-cloak class="space-y-5">            @if ($canManage)
                <x-card title="Create a role" subtitle="Custom roles can be assigned to members with a tailored permission set.">
                    <form method="POST" action="{{ route('settings.roles.store') }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-input name="key" label="Key" placeholder="e.g. auditor" required />
                            <x-input name="name" label="Name" placeholder="e.g. Internal auditor" required />
                            <x-input name="approval_limit" type="number" step="0.01" min="0" label="Approval limit" help="Leave blank for no limit" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-slate-700">Permissions</p>
                            <div class="mt-2 grid max-h-64 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($permissions as $permission)
                                    <label class="flex items-center gap-2 text-xs text-slate-600">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission }}" class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span class="font-mono">{{ $permission }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create role</button>
                    </form>
                </x-card>
            @endif

            <x-card title="Roles" :subtitle="$roles->count().' role(s)'" :padding="false">
                <div class="divide-y divide-slate-100">
                    @foreach ($roles as $role)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $role->name }}
                                        @if ($role->is_system)
                                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-slate-500">system</span>
                                        @endif
                                    </p>
                                    <p class="font-mono text-xs text-slate-400">{{ $role->key }} · {{ count($role->permissions ?? []) }} permission(s) · {{ $role->members_count ?? $role->members()->count() }} member(s)</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-slate-500">Limit: {{ $role->approval_limit !== null ? \App\Support\Money::format($role->approval_limit) : 'none' }}</span>
                                    <x-icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                                </div>
                            </summary>
                            <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-4">
                                @if ($canManage && $role->key !== 'member')
                                    <form method="POST" action="{{ route('settings.roles.update', $role) }}" class="space-y-4">
                                        @csrf
                                        @method('PUT')
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <x-input name="name" label="Name" :value="$role->name" required />
                                            <x-input name="approval_limit" type="number" step="0.01" min="0" label="Approval limit" :value="$role->approval_limit !== null ? $role->approval_limit / 100 : null" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-slate-700">Permissions</p>
                                            <div class="mt-2 grid max-h-72 gap-1 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-3">
                                                @foreach ($permissions as $permission)
                                                    <label class="flex items-center gap-2 text-xs text-slate-600">
                                                        <input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, $role->permissions ?? [], true) || in_array('*', $role->permissions ?? [], true)) class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                        <span class="font-mono">{{ $permission }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save role</button>
                                    </form>
                                @else
                                    <p class="text-sm text-slate-500">This role cannot be modified.</p>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            </x-card>
        </div>

        <div x-show="tab === 'templates'" x-cloak class="space-y-5">
            <x-card title="Notification templates" :subtitle="$templates->count().' template(s)'" :padding="false">
                <div class="divide-y divide-slate-100">
                    @forelse ($templates as $template)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $template->name }}</p>
                                    <p class="font-mono text-xs text-slate-400">{{ $template->key }} · {{ $template->channel }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-badge :status="$template->is_active ? 'active' : 'inactive'" :label="$template->is_active ? 'Active' : 'Disabled'" />
                                    <x-icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                                </div>
                            </summary>
                            <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-4">
                                @if ($canManage)
                                    <form method="POST" action="{{ route('settings.templates.update', $template) }}" class="space-y-3">
                                        @csrf
                                        @method('PUT')
                                        <x-input name="subject" label="Subject" :value="$template->subject" />
                                        <x-textarea name="body" label="Body" rows="5" :value="$template->body" help="Use placeholders such as member_name. Available: {{ implode(', ', $template->variables ?? []) ?: 'none' }}" required />
                                        <label class="flex items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" name="is_active" value="1" @checked($template->is_active) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            Active
                                        </label>
                                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save template</button>
                                    </form>
                                @else
                                    <p class="whitespace-pre-line text-sm text-slate-600">{{ $template->body }}</p>
                                @endif
                            </div>
                        </details>
                    @empty
                        <x-empty title="No templates" message="Notification templates are seeded with the group." class="m-4" />
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
@endsection
