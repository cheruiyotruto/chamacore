<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberEdmsService;
use App\Support\JoinRequirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class JoinController extends Controller
{
    public function show(string $token): View
    {
        [$invitation, $group, $inviter] = $this->resolve($token);

        $requirements = JoinRequirements::for($group);

        if ($invitation->requiresBiometric()) {
            $requirements = $requirements->withBiometricRequirement(true);
        }

        return view('join.show', [
            'invitation' => $invitation,
            'group' => $group,
            'inviter' => $inviter,
            'requirements' => $requirements,
        ]);
    }

    public function store(Request $request, string $token, MemberEdmsService $edms): RedirectResponse
    {
        [$invitation, $group] = $this->resolve($token);

        if (! $invitation->canBeUsed()) {
            return redirect()->route('join.show', $token)
                ->with('error', $this->unavailableMessage($invitation));
        }

        if ($group->status !== 'active') {
            return redirect()->route('join.show', $token)
                ->with('error', 'This group is not accepting new applications right now.');
        }

        $requirements = JoinRequirements::for($group);

        if ($invitation->requiresBiometric()) {
            $requirements = $requirements->withBiometricRequirement(true);
        }

        $data = $request->validate($requirements->validationRules());

        $photo = $requirements->mediaEnabled('passport_photo') ? $request->file('passport_photo') : null;
        $video = $requirements->mediaEnabled('face_video') ? $request->file('face_video') : null;

        $identityErrors = $requirements->identityErrors($photo !== null, $video !== null);

        if ($identityErrors !== []) {
            throw ValidationException::withMessages($identityErrors);
        }

        [$member, $user, $isNewUser] = DB::transaction(function () use ($requirements, $photo, $video, $invitation, $edms, $data, $request) {
            /** @var MemberInvitation|null $locked */
            $locked = MemberInvitation::withoutGlobalScopes()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->canBeUsed()) {
                throw ValidationException::withMessages([
                    'email' => 'This invitation is no longer available. Ask the member who invited you for a new link.',
                ]);
            }

            $group = Group::withoutGlobalScopes()->find($locked->group_id);

            if (! $group || $group->status !== 'active') {
                throw ValidationException::withMessages([
                    'email' => 'This group is not accepting new applications right now.',
                ]);
            }

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
                'notes' => 'Applied through invitation '.$locked->token.'.',
                'metadata' => [
                    'invitation_id' => $locked->id,
                    'inviter_member_id' => $locked->inviter_member_id,
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

            $media = $edms->storeIdentityMedia(
                $group,
                $member,
                $user,
                $photo,
                $video,
                'Invitation '.$locked->uuid
            );

            $attributes = [];
            $metadata = $member->metadata ?? [];

            if ($locked->requiresBiometric()) {
                $metadata['biometric_required'] = true;
            }

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
                ? (string) $request->input('signature', '')
                : null;
            $typedSignature = $requirements->documentEnabled(JoinRequirements::TYPED_SIGNATURE_KEY)
                ? (string) $request->input('typed_signature', '')
                : null;

            $submittedDocuments = $edms->storeApplicationDocuments(
                $group,
                $member,
                $user,
                $documentRequirements,
                $uploads,
                $signature,
                'Invitation '.$locked->uuid,
                $typedSignature,
                (bool) $request->boolean('typed_signature_consent')
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

            $locked->update([
                'status' => MemberInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_member_id' => $member->id,
            ]);

            return [$member, $user, $isNewUser];
        });

        if ($isNewUser || ! $user->email_verified_at) {
            try {
                Password::sendResetLink(['email' => $member->email]);
            } catch (Throwable $e) {
                Log::warning('member.invitation.password_link.failed', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('join.thanks', $token)
            ->with('status', 'Thank you, '.$member->first_name.'. Your application has been submitted for approval.');
    }

    public function thanks(string $token): View|RedirectResponse
    {
        [$invitation, $group, $inviter] = $this->resolve($token);

        if (! $invitation->accepted_member_id) {
            return redirect()->route('join.show', $token);
        }

        return view('join.thanks', [
            'invitation' => $invitation,
            'group' => $group,
            'inviter' => $inviter,
            'member' => Member::withoutGlobalScopes()->find($invitation->accepted_member_id),
        ]);
    }

    /**
     * @return array{0: MemberInvitation, 1: Group, 2: ?Member}
     */
    private function resolve(string $token): array
    {
        $invitation = MemberInvitation::withoutGlobalScopes()
            ->where('token', $token)
            ->first();

        abort_if($invitation === null, 404);

        $group = Group::withoutGlobalScopes()->find($invitation->group_id);
        abort_if($group === null, 404);

        $inviter = $invitation->inviter_member_id
            ? Member::withoutGlobalScopes()->find($invitation->inviter_member_id)
            : null;

        return [$invitation, $group, $inviter];
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

    private function unavailableMessage(MemberInvitation $invitation): string
    {
        return match ($invitation->effectiveStatus()) {
            MemberInvitation::STATUS_ACCEPTED => 'This invitation has already been used. Sign in to check your application status.',
            MemberInvitation::STATUS_REVOKED => 'This invitation has been revoked. Ask the member who invited you for a new link.',
            MemberInvitation::STATUS_EXPIRED => 'This invitation has expired. Ask the member who invited you for a new link.',
            default => 'This invitation is no longer available.',
        };
    }
}
