@extends('layouts.app')

@section('title', 'Branches')

@section('content')
    <x-page-header title="Branches"
        :subtitle="$organization->name.' · '.$branchCount.' branch(es) · '.$totalMembers.' member(s) in this tree'">
        <a href="{{ route('settings.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Settings
        </a>
    </x-page-header>

    <x-card title="How branches work"
        subtitle="Each branch keeps its own members, wallets, loans, savings and ledger. The head office subscription covers every branch.">
        <div class="grid gap-3 text-sm text-slate-600 sm:grid-cols-3">
            <p><span class="font-semibold text-slate-800">Head office:</span> {{ $organization->name }}</p>
            <p><span class="font-semibold text-slate-800">Working as:</span> {{ $current->branchPath() }}</p>
            <p><span class="font-semibold text-slate-800">Billing owner:</span> {{ $organization->name }}</p>
        </div>
    </x-card>

    @if ($current->isHeadOffice() || $current->branches()->exists())
        <x-card class="mt-5" title="Add a branch" subtitle="A branch manager is attached automatically so the branch can be administered immediately.">
            <form method="POST" action="{{ route('branches.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-input name="name" label="Branch name" placeholder="e.g. Mombasa Branch" required />
                <x-input name="branch_code" label="Branch code" placeholder="MOMBASA" help="Optional; generated from the name when blank." />
                <x-input name="email" type="email" label="Branch email" />
                <x-input name="phone" label="Branch phone" />
                <x-input name="county" label="County" />
                <x-input name="sub_county" label="Sub-county" />
                <div class="sm:col-span-2">
                    <x-input name="address" label="Physical address" />
                </div>
                <div class="sm:col-span-2">
                    <x-textarea name="description" label="Notes" rows="2" />
                </div>

                <div class="sm:col-span-2 border-t border-slate-100 pt-4">
                    <p class="mb-3 text-sm font-semibold text-slate-900">Branch manager</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input name="manager_name" label="Full name" />
                        <x-input name="manager_email" type="email" label="Email" help="Leave blank to use your own account as manager." />
                        <x-input name="manager_phone" label="Phone" />
                        <x-select name="manager_role" label="Role" :options="$managerRoles" :value="old('manager_role', 'group_admin')" />
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create branch</button>
                </div>
            </form>
        </x-card>
    @endif

    <x-card class="mt-5" title="Branches" :padding="false">
        @if ($branches->isEmpty())
            <x-empty title="No branches yet" message="Create a branch above to give another location its own isolated books." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Branch</th>
                            <th class="px-4 py-3">Parent</th>
                            <th class="px-4 py-3">Members</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($branches as $branch)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900">{{ $branch->name }}</p>
                                    <p class="text-xs text-slate-400">{{ $branch->branch_code }} · {{ $branch->county ?: 'No county' }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $branch->parent?->name ?? $organization->name }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $branch->active_members_count }} active / {{ $branch->members_count }}</td>
                                <td class="px-4 py-3"><x-badge :status="$branch->status" /></td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-3">
                                        @if ($branch->status === 'active' && in_array($branch->id, $membershipGroupIds, true))
                                            <form method="POST" action="{{ route('profile.switch-group', $branch->id) }}">
                                                @csrf
                                                <button class="text-xs font-semibold text-brand-700 hover:underline">Open</button>
                                            </form>
                                        @endif

                                        @if ($branch->can_manage)
                                            <details>
                                                <summary class="cursor-pointer text-xs font-semibold text-slate-600 hover:text-slate-900">Edit</summary>
                                                <form method="POST" action="{{ route('branches.update', $branch) }}" class="mt-3 grid w-80 gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                                                    @csrf
                                                    @method('PUT')
                                                    <x-input name="name" label="Name" :value="$branch->name" required />
                                                    <x-input name="branch_code" label="Code" :value="$branch->branch_code" />
                                                    <x-input name="email" label="Email" :value="$branch->email" />
                                                    <x-input name="phone" label="Phone" :value="$branch->phone" />
                                                    <x-select name="status" label="Status" :options="['active' => 'Active', 'suspended' => 'Suspended']" :value="$branch->status" />
                                                    <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">Save branch</button>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ route('branches.toggle', $branch) }}">
                                                @csrf
                                                <button class="text-xs font-semibold {{ $branch->status === 'active' ? 'text-rose-700' : 'text-emerald-700' }} hover:underline">
                                                    {{ $branch->status === 'active' ? 'Suspend' : 'Activate' }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection
