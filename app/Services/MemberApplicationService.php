<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\JoinRequirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared application workflow for invitation links and the public website.
 * Applications always start as "applied" and never receive group data until a
 * super admin or group official explicitly approves them.
 */
final class MemberApplicationService
{
    /**
     * @param  array<string, mixed>  $data  already-validated form data
     * @return array{0: Member, 1: User, 2: bool}
     */
    public function submit(
        Group $group,
        array $data,
        Request $request,
        JoinRequirements $requirements,
        MemberEdmsService $edms,
        string $source
    ): array {
        [$member, $user, $isNewUser] = DB::transaction(function () use ($group, $data, $request, $requirements, $edms, $source) {
            $role = Role::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('key', 'member')
                ->first();

            if (! $role) {
                throw ValidationException::withMessages([
                    'email' => 'The group has not finished setting up member roles. Please contact the group office.',
                ]);
            }

            if ($requirements->fieldEnabled('national_id') && filled($data['national_id'] ?? null)
                && Member::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('group_id', $group->id)
                    ->where('national_id', $data['national_id'])
                    ->exists()) {
                throw ValidationException::withMessages([
                    'national_id' => 'A member with this national ID already exists in this group.',
                ]);
            }

            $user = User::where('email', $data['email'])->first();
            $isNewUser = false;

            if ($user && Member::withoutGlobalScopes()
                ->withTrashed()
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email address already belongs to a member of this group. Sign in instead.',
                ]);
            }

            if (! $user) {
                $user = User::create([
                    'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Str::random(40),
                    'status' => 'active',
                ]);
                $isNewUser = true;
            }

            $member = Member::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'member_no' => $this->nextMemberNumber($group->id),
                'first_name' => $data['first_name'] ?? '',
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'] ?? '',
                'national_id' => $data['national_id'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'address' => $data['address'] ?? null,
                'occupation' => $data['occupation'] ?? null,
                'employer' => $data['employer'] ?? null,
                'employer_address' => $data['employer_address'] ?? null,
                'workstation' => $data['workstation'] ?? null,
                'membership_category' => $data['membership_category'] ?? 'ordinary',
                'status' => 'applied',
                'kyc_status' => 'pending',
                'notes' => 'Applied through '.$source.'.',
                'metadata' => [
                    'application_source' => $source,
                    'submitted_at' => now()->toIso8601String(),
                ],
            ]);

            if ($requirements->fieldEnabled('next_of_kin_name')) {
                $member->nextOfKin()->create([
                    'group_id' => $group->id,
                    'name' => $data['next_of_kin_name'] ?? 'Not provided',
                    'relationship' => $data['next_of_kin_relationship'] ?? 'Not specified',
                    'phone' => $data['next_of_kin_phone'] ?? 'Not provided',
                ]);
            }

            $photo = $requirements->mediaEnabled('passport_photo') ? $request->file('passport_photo') : null;
            $video = $requirements->mediaEnabled('face_video') ? $request->file('face_video') : null;

            $media = $edms->storeIdentityMedia($group, $member, $user, $photo, $video, $source);

            $attributes = [];
            $metadata = $member->metadata ?? [];

            if ($media['photo']) {
                $attributes['photo_path'] = $media['photo']->path;
            }

            if ($media['video']) {
                $metadata['face_video_path'] = $media['video']->path;
                $metadata['face_video_document_id'] = $media['video']->id;
            }

            if ($requirements->fieldEnabled('kra_pin_number') && filled($data['kra_pin_number'] ?? null)) {
                $metadata['kra_pin_number'] = mb_substr(trim(strip_tags((string) $data['kra_pin_number'])), 0, 30);
            }

            $documentRequirements = $requirements->enabledDocuments();
            $uploads = [];

            foreach ($documentRequirements as $key => $definition) {
                if ($definition['signature'] ?? false) {
                    continue;
                }

                $file = $request->file('documents.'.$key);

                if ($file) {
                    $uploads[$key] = $file;
                }
            }

            $signature = $requirements->documentEnabled(JoinRequirements::SIGNATURE_KEY)
                ? (string) ($data['signature'] ?? '')
                : null;
            $typedSignature = $requirements->documentEnabled(JoinRequirements::TYPED_SIGNATURE_KEY)
                ? (string) ($data['typed_signature'] ?? '')
                : null;

            $submittedDocuments = $edms->storeApplicationDocuments(
                $group,
                $member,
                $user,
                $documentRequirements,
                $uploads,
                $signature,
                $source,
                $typedSignature,
                (bool) ($data['typed_signature_consent'] ?? false)
            );

            $signatureMetadata = $edms->signatureMetadata($submittedDocuments, $typedSignature);

            if ($signatureMetadata !== null) {
                $metadata['electronic_signature'] = $signatureMetadata;
            }

            $requiredDocuments = $requirements->requiredDocuments();

            if ($requiredDocuments !== [] || $submittedDocuments !== []) {
                $metadata['application_documents'] = [
                    'required' => $requiredDocuments,
                    'submitted' => $submittedDocuments,
                    'submitted_at' => now()->toIso8601String(),
                ];
            }

            $attributes['metadata'] = $metadata;
            $member->update($attributes);

            return [$member, $user, $isNewUser];
        });

        if ($isNewUser || ! $user->email_verified_at) {
            try {
                Password::sendResetLink(['email' => $member->email]);
            } catch (Throwable $e) {
                Log::warning('member.application.password_link.failed', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$member, $user, $isNewUser];
    }

    /**
     * @return array<int, string>
     */
    public function missingDocuments(Member $member): array
    {
        return app(MemberEdmsService::class)->missingRequiredDocuments($member);
    }

    public function approve(Member $member, User $actor, bool $overrideDocuments = false): void
    {
        if (! in_array($member->status, ['applied', 'approved'], true)) {
            throw ValidationException::withMessages([
                'member' => 'Only pending members can be approved.',
            ]);
        }

        $missingDocuments = $this->missingDocuments($member);

        if ($missingDocuments !== [] && ! $overrideDocuments) {
            throw ValidationException::withMessages([
                'override_documents' => 'Required joining documents are missing: '.implode(', ', $missingDocuments),
            ]);
        }

        DB::transaction(function () use ($member, $actor) {
            $member->update([
                'status' => 'active',
                'kyc_status' => 'verified',
                'joined_at' => $member->joined_at ?? now(),
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            app(SavingsService::class)->openDefaultAccounts($member);
            app(WalletService::class)->ensureWallet($member);

            AuditService::record('member.approved', $member, ['status' => 'applied'], ['status' => 'active']);
        });

        // Invited guarantors become active guarantees only after approval, and
        // then wait for the member to deposit the required qualifying savings.
        app(LoanOriginationService::class)->activateInvitationGuaranteesForMember($member->fresh());

        if ($missingDocuments !== []) {
            AuditService::log('member.approved.without_documents', [
                'member_id' => $member->id,
                'missing_documents' => $missingDocuments,
                'approved_by' => $actor->id,
            ], $this->groupFor($member));
        }

        app(NotificationService::class)->send($member, 'member_approved', [
            'member_name' => $member->full_name,
            'group_name' => $this->groupFor($member)?->name ?? config('app.name'),
        ]);
    }

    public function reject(Member $member, User $actor, string $reason): void
    {
        if (! in_array($member->status, ['applied', 'approved'], true)) {
            throw ValidationException::withMessages([
                'member' => 'Only pending members can be rejected.',
            ]);
        }

        $member->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        AuditService::record('member.rejected', $member, null, ['reason' => $reason], $member->group_id);
    }

    private function groupFor(Member $member): ?Group
    {
        return Group::withoutGlobalScopes()->find($member->group_id);
    }

    private function nextMemberNumber(int $groupId): string
    {
        $highest = Member::withoutGlobalScopes()
            ->withTrashed()
            ->where('group_id', $groupId)
            ->pluck('member_no')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'M'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }
}
