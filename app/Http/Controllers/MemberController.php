<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LoanOriginationService;
use App\Services\MemberEdmsService;
use App\Services\NotificationService;
use App\Services\SavingsService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('members.view');

        $members = Member::query()
            ->with(['role', 'user'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($inner) use ($search) {
                    $inner->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('member_no', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('national_id', 'like', $search);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('role'), fn ($query) => $query->whereHas('role', fn ($q) => $q->where('key', $request->string('role'))))
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        return view('members.index', [
            'members' => $members,
            'roles' => Role::orderBy('name')->get(),
            'statuses' => ['applied', 'approved', 'active', 'suspended', 'rejected', 'terminated'],
        ]);
    }

    public function create(): View
    {
        $this->requirePermission('members.create');

        return view('members.create', [
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('members.create');
        $group = $this->group();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'employer' => ['nullable', 'string', 'max:120'],
            'workstation' => ['nullable', 'string', 'max:150'],
            'membership_category' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['applied', 'approved', 'active'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $role = Role::where('key', $data['role'])->first();

        if (! $role) {
            throw ValidationException::withMessages(['role' => 'The selected role does not exist in this group.']);
        }

        if ($data['national_id'] ?? null) {
            $duplicate = Member::where('national_id', $data['national_id'])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['national_id' => 'A member with this national ID already exists.']);
            }
        }

        $status = $data['status'] ?? ($role->key === 'member' ? 'applied' : 'active');

        $member = DB::transaction(function () use ($group, $data, $role, $status) {
            $user = User::where('email', $data['email'])->first();

            if ($user && $user->memberships()->where('group_id', $group->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This user already belongs to your group.']);
            }

            if (! $user) {
                $user = User::create([
                    'name' => trim($data['first_name'].' '.$data['last_name']),
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Str::random(40),
                    'status' => 'active',
                ]);
            }

            $sequence = Member::withoutGlobalScopes()->where('group_id', $group->id)->count() + 1;

            $member = Member::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'member_no' => 'M'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'national_id' => $data['national_id'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'address' => $data['address'] ?? null,
                'occupation' => $data['occupation'] ?? null,
                'employer' => $data['employer'] ?? null,
                'workstation' => $data['workstation'] ?? null,
                'membership_category' => $data['membership_category'] ?? 'ordinary',
                'status' => $status,
                'kyc_status' => 'pending',
                'joined_at' => $status === 'active' ? now() : null,
                'approved_by' => $status === 'active' ? $this->actor()->id : null,
                'approved_at' => $status === 'active' ? now() : null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($status === 'active') {
                app(SavingsService::class)->openDefaultAccounts($member);
                app(WalletService::class)->ensureWallet($member);
            }

            AuditService::record('member.created', $member, null, $member->only([
                'member_no', 'first_name', 'last_name', 'status', 'role_id',
            ]));

            return $member;
        });

        if (! $member->user->email_verified_at) {
            try {
                Password::sendResetLink(['email' => $member->email]);
            } catch (Throwable $e) {
                Log::warning('member.invite.failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('members.show', $member)
            ->with('status', 'Member '.$member->member_no.' was created.');
    }

    public function show(Member $member): View
    {
        $this->authorize('view', $member);

        if ($this->context()->isMember()) {
            abort_unless($member->id === $this->context()->memberId(), 403);
        }

        $member->load([
            'user', 'role', 'savingsAccounts.product', 'activeLoans.product',
            'guarantees.loan', 'wallet', 'documents', 'nextOfKin', 'beneficiaries',
        ]);

        $fines = \App\Models\Fine::where('member_id', $member->id)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->latest()
            ->get();

        return view('members.show', [
            'member' => $member,
            'fines' => $fines,
            'savingsTotal' => (int) $member->savingsAccounts->sum('balance'),
            'loanBalance' => (int) $member->activeLoans->sum(fn ($loan) => $loan->outstandingTotal()),
            'missingDocuments' => app(MemberEdmsService::class)->missingRequiredDocuments($member),
        ]);
    }

    public function edit(Member $member): View
    {
        $this->authorize('update', $member);

        $member->load(['nextOfKin', 'beneficiaries']);

        $canManage = ! $this->context()->isMember()
            && $this->context()->hasPermission('members.update');

        return view('members.edit', [
            'member' => $member,
            'canManage' => $canManage,
            'roles' => Role::orderBy('name')->get(),
            'kinRows' => old('next_of_kin', $member->nextOfKin->map(fn ($kin) => [
                'id' => $kin->id,
                'name' => $kin->name,
                'relationship' => $kin->relationship,
                'phone' => $kin->phone,
                'email' => $kin->email,
                'address' => $kin->address,
                'id_number' => $kin->id_number,
            ])->values()->all()) ?? [],
            'beneficiaryRows' => old('beneficiaries', $member->beneficiaries->map(fn ($beneficiary) => [
                'id' => $beneficiary->id,
                'name' => $beneficiary->name,
                'relationship' => $beneficiary->relationship,
                'phone' => $beneficiary->phone,
                'id_number' => $beneficiary->id_number,
                'allocation_percent' => $beneficiary->allocation_percent,
                'is_primary' => (bool) $beneficiary->is_primary,
            ])->values()->all()) ?? [],
        ]);
    }

    public function update(Request $request, Member $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $isStaff = ! $this->context()->isMember();
        $canManage = $isStaff && $this->context()->hasPermission('members.update');

        // Viewer-level staff must not be able to submit administrative fields.
        $sensitiveFields = ['email', 'role_id', 'status', 'kyc_status', 'joined_at', 'membership_fee_paid', 'biometric_required'];
        if ($isStaff && array_intersect(array_keys($request->all()), $sensitiveFields) !== []) {
            $this->authorize('updateSensitive', $member);
        }

        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'employer' => ['nullable', 'string', 'max:120'],
            'workstation' => ['nullable', 'string', 'max:150'],
            'membership_category' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'next_of_kin_present' => ['nullable', 'boolean'],
            'next_of_kin' => ['nullable', 'array'],
            'next_of_kin.*.id' => ['nullable', 'integer'],
            'next_of_kin.*.name' => ['nullable', 'string', 'max:150'],
            'next_of_kin.*.relationship' => ['nullable', 'string', 'max:60'],
            'next_of_kin.*.phone' => ['nullable', 'string', 'max:30'],
            'next_of_kin.*.email' => ['nullable', 'email', 'max:190'],
            'next_of_kin.*.address' => ['nullable', 'string', 'max:500'],
            'next_of_kin.*.id_number' => ['nullable', 'string', 'max:40'],
            'beneficiaries_present' => ['nullable', 'boolean'],
            'beneficiaries' => ['nullable', 'array'],
            'beneficiaries.*.id' => ['nullable', 'integer'],
            'beneficiaries.*.name' => ['nullable', 'string', 'max:150'],
            'beneficiaries.*.relationship' => ['nullable', 'string', 'max:60'],
            'beneficiaries.*.phone' => ['nullable', 'string', 'max:30'],
            'beneficiaries.*.id_number' => ['nullable', 'string', 'max:40'],
            'beneficiaries.*.allocation_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'beneficiaries.*.is_primary' => ['nullable', 'boolean'],
        ];

        if ($canManage) {
            $rules['email'] = ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($member->user_id)];
            $rules['employer_address'] = ['nullable', 'string', 'max:500'];
            $rules['role_id'] = ['nullable', 'integer'];
            $rules['kyc_status'] = ['nullable', Rule::in(['pending', 'verified', 'rejected'])];
            $rules['status'] = ['required', Rule::in(['applied', 'approved', 'active', 'suspended', 'rejected', 'terminated'])];
            $rules['joined_at'] = ['nullable', 'date'];
            $rules['membership_fee_paid'] = ['nullable', 'numeric', 'min:0'];
            $rules['biometric_required'] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);

        if (! empty($data['national_id'])) {
            $duplicate = Member::where('national_id', $data['national_id'])
                ->whereKeyNot($member->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'national_id' => 'A member with this national ID already exists in this group.',
                ]);
            }
        }

        if ($canManage && ! empty($data['role_id'])) {
            $role = Role::whereKey($data['role_id'])->firstOrFail();
            $this->assertRoleAssignable($role, $member);
            $data['role_id'] = $role->id;
        }

        if ($canManage
            && isset($data['status'])
            && $data['status'] !== $member->status
            && ($member->user_id === $this->actor()->id || ! $this->statusChangeAllowed($data['status']))
        ) {
            throw ValidationException::withMessages([
                'status' => 'You are not authorised for this status change.',
            ]);
        }

        if ($canManage
            && in_array($data['status'] ?? null, ['rejected', 'terminated'], true)
            && $data['status'] !== $member->status
            && $member->activeLoans()->exists()) {
            throw ValidationException::withMessages([
                'status' => 'A member with an active loan cannot be rejected or terminated.',
            ]);
        }

        $editable = [
            'first_name', 'middle_name', 'last_name', 'phone', 'national_id', 'gender',
            'date_of_birth', 'address', 'occupation', 'employer', 'employer_address',
            'workstation', 'membership_category', 'notes',
        ];

        $memberData = array_intersect_key($data, array_flip($editable));

        if ($canManage) {
            $memberData['email'] = $data['email'];
            $memberData['status'] = $data['status'];
            $memberData['joined_at'] = $data['joined_at'] ?? null;
            $memberData['membership_fee_paid'] = Money::toMinor($data['membership_fee_paid'] ?? 0);

            if (! empty($data['role_id'])) {
                $memberData['role_id'] = $data['role_id'];
            }

            if (! empty($data['kyc_status'])) {
                $memberData['kyc_status'] = $data['kyc_status'];
            }

            $member->setBiometricRequired((bool) ($data['biometric_required'] ?? false));
            $memberData['metadata'] = $member->metadata;
        }

        $becomingActive = $canManage && ($memberData['status'] ?? null) === 'active' && $member->status !== 'active';

        if ($becomingActive) {
            $memberData['joined_at'] = $memberData['joined_at'] ?? now();
            $memberData['approved_by'] = $this->actor()->id;
            $memberData['approved_at'] = now();
            $memberData['rejection_reason'] = null;
        }

        DB::transaction(function () use ($request, $member, $memberData, $data, $canManage, $becomingActive) {
            $old = $member->only(array_keys($memberData));
            $member->update($memberData);

            if ($member->user) {
                $userData = [];

                if (array_key_exists('first_name', $memberData) || array_key_exists('last_name', $memberData)) {
                    $userData['name'] = trim($member->first_name.' '.$member->last_name);
                }

                if ($canManage && array_key_exists('email', $memberData)) {
                    $userData['email'] = $memberData['email'];
                }

                if ($userData !== []) {
                    $member->user->update($userData);
                }
            }

            if ($becomingActive) {
                app(SavingsService::class)->openDefaultAccounts($member);
                app(WalletService::class)->ensureWallet($member);
            }

            if ($request->boolean('next_of_kin_present')) {
                $this->syncNextOfKin($member, $data['next_of_kin'] ?? []);
            }

            if ($request->boolean('beneficiaries_present')) {
                $this->syncBeneficiaries($member, $data['beneficiaries'] ?? []);
            }

            AuditService::record('member.updated', $member, $old, $member->only(array_keys($memberData)));
        });

        return redirect()->route('members.show', $member)->with('status', 'Member details updated.');
    }

    /**
     * A staff member may never assign a role above their own level, change
     * their own role, or change their own account status.
     */
    private function assertRoleAssignable(Role $role, Member $member): void
    {
        $hierarchy = config('chama.role_hierarchy', []);
        $actorRank = array_search($this->context()->roleKey(), $hierarchy, true);
        $targetRank = array_search($role->key, $hierarchy, true);

        if ($actorRank === false || $targetRank === false || $targetRank < $actorRank) {
            throw ValidationException::withMessages([
                'role_id' => 'You cannot assign a role above your own level.',
            ]);
        }

        if ($member->user_id === $this->actor()->id && $role->id !== $member->role_id) {
            throw ValidationException::withMessages([
                'role_id' => 'You cannot change your own role.',
            ]);
        }
    }

    private function statusChangeAllowed(string $status): bool
    {
        $permission = match (true) {
            in_array($status, ['approved', 'active'], true) => 'members.approve',
            in_array($status, ['rejected', 'terminated'], true) => 'members.terminate',
            default => null,
        };

        return $permission === null || $this->context()->hasPermission($permission);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncNextOfKin(Member $member, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $payload = [
                'name' => $name,
                'relationship' => trim((string) ($row['relationship'] ?? '')),
                'phone' => trim((string) ($row['phone'] ?? '')),
                'email' => filled($row['email'] ?? null) ? $row['email'] : null,
                'address' => filled($row['address'] ?? null) ? $row['address'] : null,
                'id_number' => filled($row['id_number'] ?? null) ? $row['id_number'] : null,
            ];

            $id = (int) ($row['id'] ?? 0);
            $kin = $id > 0 ? $member->nextOfKin()->whereKey($id)->first() : null;

            if ($kin) {
                $kin->update($payload);
                $keep[] = $kin->id;
            } else {
                $keep[] = $member->nextOfKin()->create($payload + ['group_id' => $member->group_id])->id;
            }
        }

        if ($keep === []) {
            $member->nextOfKin()->delete();
        } else {
            $member->nextOfKin()->whereNotIn('id', $keep)->delete();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncBeneficiaries(Member $member, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $payload = [
                'name' => $name,
                'relationship' => trim((string) ($row['relationship'] ?? '')),
                'phone' => filled($row['phone'] ?? null) ? $row['phone'] : null,
                'id_number' => filled($row['id_number'] ?? null) ? $row['id_number'] : null,
                'allocation_percent' => (int) ($row['allocation_percent'] ?? 0),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ];

            $id = (int) ($row['id'] ?? 0);
            $beneficiary = $id > 0 ? $member->beneficiaries()->whereKey($id)->first() : null;

            if ($beneficiary) {
                $beneficiary->update($payload);
                $keep[] = $beneficiary->id;
            } else {
                $keep[] = $member->beneficiaries()->create($payload + ['group_id' => $member->group_id])->id;
            }
        }

        if ($keep === []) {
            $member->beneficiaries()->delete();
        } else {
            $member->beneficiaries()->whereNotIn('id', $keep)->delete();
        }
    }

    public function approve(
        Request $request,
        Member $member,
        SavingsService $savings,
        WalletService $wallet,
        NotificationService $notifications,
        MemberEdmsService $edms,
    ): RedirectResponse {
        $this->authorize('approve', $member);

        if (! in_array($member->status, ['applied', 'approved'], true)) {
            return back()->with('error', 'Only pending members can be approved.');
        }

        $request->validate(['override_documents' => ['nullable', 'boolean']]);

        $missingDocuments = $edms->missingRequiredDocuments($member);
        $overrideDocuments = $request->boolean('override_documents');

        if ($missingDocuments !== [] && ! $overrideDocuments) {
            return back()->with('error', 'Required joining documents are missing: '
                .implode(', ', $missingDocuments)
                .'. Upload them first, or tick "Approve without the required documents" to override.');
        }

        DB::transaction(function () use ($member, $savings, $wallet) {
            $member->update([
                'status' => 'active',
                'kyc_status' => 'verified',
                'joined_at' => $member->joined_at ?? now(),
                'approved_by' => $this->actor()->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            $savings->openDefaultAccounts($member);
            $wallet->ensureWallet($member);

            AuditService::record('member.approved', $member, ['status' => 'applied'], ['status' => 'active']);
        });

        app(LoanOriginationService::class)->activateInvitationGuaranteesForMember($member->fresh());

        if ($missingDocuments !== []) {
            AuditService::log('member.approved.without_documents', [
                'member_id' => $member->id,
                'missing_documents' => $missingDocuments,
            ], $this->group());
        }

        $notifications->send($member, 'member_approved', [
            'member_name' => $member->full_name,
            'group_name' => $this->group()->name,
        ]);

        return back()->with('status', 'Member approved and accounts opened.');
    }

    public function reject(Request $request, Member $member): RedirectResponse
    {
        $this->authorize('approve', $member);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! in_array($member->status, ['applied', 'approved'], true)) {
            return back()->with('error', 'Only pending members can be rejected.');
        }

        $member->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'approved_by' => $this->actor()->id,
            'approved_at' => now(),
        ]);

        AuditService::record('member.rejected', $member, null, ['reason' => $data['reason']]);

        return back()->with('status', 'Membership application rejected.');
    }

    public function terminate(Request $request, Member $member): RedirectResponse
    {
        $this->authorize('terminate', $member);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if ($member->activeLoans()->exists()) {
            return back()->with('error', 'A member with an active loan cannot be terminated.');
        }

        $member->update([
            'status' => 'terminated',
            'notes' => trim(($member->notes ?? '')."\nTerminated: ".$data['reason']),
        ]);

        AuditService::record('member.terminated', $member, null, ['reason' => $data['reason']]);

        return back()->with('status', 'Membership terminated.');
    }

    public function export(Request $request)
    {
        $this->requirePermission('members.export');

        $members = Member::with('role')->orderBy('member_no')->get();

        return $this->csv('members-'.now()->format('Ymd-His').'.csv', [
            'Member No', 'First name', 'Middle name', 'Last name', 'Phone', 'Email',
            'National ID', 'Role', 'Status', 'KYC', 'Joined',
        ], $members->map(fn (Member $member) => [
            $member->member_no,
            $member->first_name,
            $member->middle_name,
            $member->last_name,
            $member->phone,
            $member->email,
            $member->national_id,
            $member->role?->name,
            $member->status,
            $member->kyc_status,
            optional($member->joined_at)->toDateString(),
        ]));
    }
}
