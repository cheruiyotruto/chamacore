@extends('layouts.app')

@section('title', 'Edit '.$member->full_name)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $roleOptions = $roles->pluck('name', 'id')->all();
        $isStaff = ! $ctx->isMember();
        $statusOptions = [
            'applied' => 'Applied',
            'approved' => 'Approved',
            'active' => 'Active',
            'suspended' => 'Suspended',
            'rejected' => 'Rejected',
            'terminated' => 'Terminated',
        ];
        $kinInput = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500';
    @endphp

    <x-page-header :title="'Edit '.$member->full_name" :subtitle="$member->member_no.' · changes are written to the audit trail'">
        <a href="{{ route('members.show', $member) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to profile
        </a>
    </x-page-header>

    <form method="POST" action="{{ route('members.update', $member) }}" class="space-y-5">
        @csrf
        @method('PATCH')

        <input type="hidden" name="next_of_kin_present" value="1">
        <input type="hidden" name="beneficiaries_present" value="1">

        <x-card title="Personal details">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="first_name" label="First name" :value="$member->first_name" required />
                <x-input name="middle_name" label="Middle name" :value="$member->middle_name" />
                <x-input name="last_name" label="Last name" :value="$member->last_name" required />
                <x-select name="gender" label="Gender" placeholder="Not specified" :options="['male' => 'Male', 'female' => 'Female', 'other' => 'Other']" :value="$member->gender" />
                <x-input name="date_of_birth" type="date" label="Date of birth" :value="$member->date_of_birth" />
                <x-input name="national_id" label="National ID / passport" :value="$member->national_id" />
            </div>
        </x-card>

        <x-card title="Contact and employment">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="phone" label="Phone" :value="$member->phone" />
                @if ($isStaff)
                    <x-input name="email" type="email" label="Email address" :value="$member->email" required help="Used for login and notifications." />
                @endif
                <x-input name="occupation" label="Occupation" :value="$member->occupation" />
                <x-input name="employer" label="Employer" :value="$member->employer" />
                <x-input name="workstation" label="Workstation / work location" :value="$member->workstation" />
                @if ($isStaff)
                    <x-input name="employer_address" label="Employer address" :value="$member->employer_address" />
                @endif
                <x-input name="membership_category" label="Membership category" :value="$member->membership_category" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-textarea name="address" label="Residential address" :value="$member->address" />
                <x-textarea name="notes" label="Internal notes" :value="$member->notes" />
            </div>
        </x-card>

        @if ($isStaff)
            <x-card title="Membership administration" subtitle="Status, role and financial membership details. Activating a pending member opens their savings accounts and wallet.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-select name="role_id" label="Group role" :options="$roleOptions" :value="$member->role_id" />
                    <x-select name="kyc_status" label="KYC status" :options="['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']" :value="$member->kyc_status" />
                    <x-select name="status" label="Membership status" :options="$statusOptions" :value="$member->status" required />
                    <x-input name="joined_at" type="date" label="Joined on" :value="$member->joined_at" help="Leave blank while the member is still applying." />
                    <x-input name="membership_fee_paid" type="number" step="0.01" min="0" label="Membership fee paid (KES)" :value="number_format(($member->membership_fee_paid ?? 0) / 100, 2, '.', '')" />
                </div>
            </x-card>
        @endif

        @if ($canManage)
            <x-card title="Biometric verification" subtitle="Optional by default. Require it only for members whose application needs an extra identity check.">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-3">
                        <input type="hidden" name="biometric_required" value="0">
                        <input type="checkbox" name="biometric_required" value="1"
                               @checked($member->biometricRequired())
                               class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Require biometric capture</span>
                            <span class="mt-0.5 block text-xs text-slate-500">This member must provide a biometric file before their application can be approved.</span>
                        </span>
                    </label>
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        Current status:
                        <span class="font-semibold {{ $member->hasBiometricCapture() ? 'text-emerald-700' : 'text-amber-700' }}">
                            {{ $member->hasBiometricCapture() ? 'Captured' : 'Not captured' }}
                        </span>
                    </p>
                </div>
            </x-card>
        @endif

        <x-card title="Next of kin" subtitle="Emergency contacts for this member. Add as many as needed.">
            <div x-data="{ rows: @js($kinRows) }" class="space-y-4">
                <template x-for="(row, index) in rows" :key="'kin-' + index">
                    <div class="rounded-lg border border-slate-200 p-3">
                        <input type="hidden" :name="'next_of_kin[' + index + '][id]'" x-model="row.id">

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Full name</label>
                                <input type="text" x-model="row.name" :name="'next_of_kin[' + index + '][name]'" class="{{ $kinInput }}" placeholder="e.g. Jane Doe">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Relationship</label>
                                <input type="text" x-model="row.relationship" :name="'next_of_kin[' + index + '][relationship]'" class="{{ $kinInput }}" placeholder="e.g. Spouse">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Phone</label>
                                <input type="text" x-model="row.phone" :name="'next_of_kin[' + index + '][phone]'" class="{{ $kinInput }}" placeholder="07xx xxx xxx">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Email</label>
                                <input type="email" x-model="row.email" :name="'next_of_kin[' + index + '][email]'" class="{{ $kinInput }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">ID number</label>
                                <input type="text" x-model="row.id_number" :name="'next_of_kin[' + index + '][id_number]'" class="{{ $kinInput }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Address</label>
                                <input type="text" x-model="row.address" :name="'next_of_kin[' + index + '][address]'" class="{{ $kinInput }}">
                            </div>
                        </div>

                        <div class="mt-3 text-right">
                            <button type="button" @click="rows.splice(index, 1)" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">Remove</button>
                        </div>
                    </div>
                </template>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" @click="rows.push({ id: '', name: '', relationship: '', phone: '', email: '', address: '', id_number: '' })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="plus" class="h-4 w-4" /> Add next of kin
                    </button>
                    <p class="text-xs text-slate-500" x-show="rows.length === 0">No next of kin recorded yet.</p>
                </div>
            </div>
        </x-card>

        <x-card title="Beneficiaries" subtitle="Who should receive the member's benefits. Allocation percentages should normally add up to 100%.">
            <div x-data="{ rows: @js($beneficiaryRows) }" class="space-y-4">
                <template x-for="(row, index) in rows" :key="'beneficiary-' + index">
                    <div class="rounded-lg border border-slate-200 p-3">
                        <input type="hidden" :name="'beneficiaries[' + index + '][id]'" x-model="row.id">

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Full name</label>
                                <input type="text" x-model="row.name" :name="'beneficiaries[' + index + '][name]'" class="{{ $kinInput }}" placeholder="e.g. John Doe">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Relationship</label>
                                <input type="text" x-model="row.relationship" :name="'beneficiaries[' + index + '][relationship]'" class="{{ $kinInput }}" placeholder="e.g. Child">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Phone</label>
                                <input type="text" x-model="row.phone" :name="'beneficiaries[' + index + '][phone]'" class="{{ $kinInput }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">ID number</label>
                                <input type="text" x-model="row.id_number" :name="'beneficiaries[' + index + '][id_number]'" class="{{ $kinInput }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Allocation (%)</label>
                                <input type="number" min="0" max="100" step="1" x-model="row.allocation_percent" :name="'beneficiaries[' + index + '][allocation_percent]'" class="{{ $kinInput }}">
                            </div>
                            <label class="flex items-end gap-2 pb-2 text-sm text-slate-700">
                                <input type="checkbox" x-model="row.is_primary" :name="'beneficiaries[' + index + '][is_primary]'" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                Primary beneficiary
                            </label>
                        </div>

                        <div class="mt-3 text-right">
                            <button type="button" @click="rows.splice(index, 1)" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">Remove</button>
                        </div>
                    </div>
                </template>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" @click="rows.push({ id: '', name: '', relationship: '', phone: '', id_number: '', allocation_percent: 100, is_primary: false })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="plus" class="h-4 w-4" /> Add beneficiary
                    </button>
                    <p class="text-xs text-slate-500" x-show="rows.length === 0">No beneficiaries recorded yet.</p>
                </div>
            </div>
        </x-card>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">
                @if ($isStaff)
                    Documents, photos and ID copies are managed from the member profile's Documents section.
                @else
                    Some account details, such as your login email and password, are managed in your profile settings.
                @endif
            </p>
            <div class="flex items-center gap-3">
                <a href="{{ route('members.show', $member) }}" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save changes</button>
            </div>
        </div>
    </form>
@endsection
