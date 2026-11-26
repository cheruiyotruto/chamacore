@extends('layouts.app')

@section('title', 'Add member')

@section('content')
    @php
        $roleOptions = $roles->pluck('name', 'key')->all();
        $statusOptions = ['applied' => 'Applied', 'approved' => 'Approved', 'active' => 'Active'];
    @endphp

    <x-page-header title="Add member" subtitle="Create the membership record and invite the member to set a password.">
        <a href="{{ route('members.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back
        </a>
    </x-page-header>

    <form method="POST" action="{{ route('members.store') }}" class="space-y-5">
        @csrf

        <x-card title="Personal details">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="first_name" label="First name" :value="old('first_name')" required />
                <x-input name="middle_name" label="Middle name" :value="old('middle_name')" />
                <x-input name="last_name" label="Last name" :value="old('last_name')" required />
                <x-select name="gender" label="Gender" placeholder="Not specified" :options="['male' => 'Male', 'female' => 'Female', 'other' => 'Other']" :value="old('gender')" />
                <x-input name="date_of_birth" type="date" label="Date of birth" :value="old('date_of_birth')" />
                <x-input name="national_id" label="National ID / passport" :value="old('national_id')" />
            </div>
        </x-card>

        <x-card title="Contact and membership">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="email" type="email" label="Email address" :value="old('email')" required help="The invitation and password reset are sent here." />
                <x-input name="phone" label="Phone" :value="old('phone')" />
                <x-input name="occupation" label="Occupation" :value="old('occupation')" />
                <x-input name="employer" label="Employer" :value="old('employer')" />
                <x-select name="role" label="Group role" :options="$roleOptions" :value="old('role')" required />
                <x-select name="status" label="Initial status" :options="$statusOptions" :value="old('status')" placeholder="Decide from role" />
                <x-input name="membership_category" label="Membership category" :value="old('membership_category', 'ordinary')" />
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-textarea name="address" label="Address" :value="old('address')" />
                <x-textarea name="notes" label="Internal notes" :value="old('notes')" />
            </div>
        </x-card>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('members.index') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create member</button>
        </div>
    </form>
@endsection
