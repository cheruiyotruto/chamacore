<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\MemberInvitation;
use App\Services\InvitationService;
use App\Support\JoinRequirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function index(): View
    {
        $member = $this->membership();

        $invitations = MemberInvitation::query()
            ->with('acceptedMember')
            ->where('inviter_member_id', $member->id)
            ->latest()
            ->paginate(10);

        $requirements = JoinRequirements::for($this->group());

        return view('portal.invitations.index', [
            'invitations' => $invitations,
            'showProvidedPoints' => $requirements->portalPointsVisible(),
            'providedPoints' => $requirements->visiblePortalPoints(),
            'canRequireBiometric' => $this->context()->roleKey() === 'group_admin',
            'channels' => [
                'email' => 'Email',
                'sms' => 'SMS',
                'whatsapp' => 'WhatsApp',
            ],
        ]);
    }

    public function store(Request $request, InvitationService $invitations): RedirectResponse
    {
        $member = $this->membership();

        $data = $this->validateInvitation($request);

        // Only group administrators may turn an optional capture into a
        // mandatory one for a particular applicant.
        $data['require_biometric'] = $this->context()->roleKey() === 'group_admin'
            && $request->boolean('require_biometric');

        $invitation = $invitations->create($member, $data);

        return $this->deliveryResponse($invitation, 'Invitation created');
    }

    public function resend(MemberInvitation $invitation, InvitationService $invitations): RedirectResponse
    {
        $this->authorizeInvitation($invitation);

        if (! $invitation->canBeUsed()) {
            return back()->with('error', 'Only pending invitations can be resent.');
        }

        $invitations->send($invitation);

        return $this->deliveryResponse($invitation->refresh(), 'Invitation resent');
    }

    public function revoke(MemberInvitation $invitation): RedirectResponse
    {
        $this->authorizeInvitation($invitation);

        if ($invitation->status === MemberInvitation::STATUS_ACCEPTED) {
            return back()->with('error', 'This invitation has already been used by an applicant.');
        }

        if ($invitation->status === MemberInvitation::STATUS_REVOKED) {
            return back()->with('error', 'This invitation is already revoked.');
        }

        $invitation->update(['status' => MemberInvitation::STATUS_REVOKED]);

        return back()->with('status', 'Invitation revoked. The join link no longer works.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInvitation(Request $request): array
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
            'require_biometric' => ['nullable', 'boolean'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(MemberInvitation::CHANNELS)],
            'expires_in' => ['nullable', 'integer', Rule::in([7, 14, 30, 90])],
        ]);

        $channels = array_values(array_unique($data['channels']));

        $errors = [];

        if (in_array('email', $channels, true) && blank($data['email'] ?? null)) {
            $errors['email'] = 'Add an email address to send an email invitation.';
        }

        if (array_intersect($channels, ['sms', 'whatsapp']) && blank($data['phone'] ?? null)) {
            $errors['phone'] = 'Add a phone number to send SMS or WhatsApp invitations.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['channels'] = $channels;
        $data['expires_in'] = (int) ($data['expires_in'] ?? 14);

        return $data;
    }

    private function authorizeInvitation(MemberInvitation $invitation): void
    {
        $member = $this->membership();

        $isOwner = $invitation->inviter_member_id === $member->id;
        $isAdmin = $this->context()->roleKey() === 'group_admin';

        abort_unless($isOwner || $isAdmin, 403);
    }

    private function deliveryResponse(MemberInvitation $invitation, string $prefix): RedirectResponse
    {
        /** @var Collection<string, array{status?:string, error?:?string}> $delivery */
        $delivery = collect($invitation->delivery ?? []);

        $sent = $delivery->filter(fn ($result) => ($result['status'] ?? null) === 'sent')->keys();
        $failed = $delivery->filter(fn ($result) => ($result['status'] ?? null) === 'failed')->keys();

        $labels = ['email' => 'email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

        $status = $prefix.'.';
        if ($sent->isNotEmpty()) {
            $status .= ' Delivered via '.$sent->map(fn ($channel) => $labels[$channel] ?? $channel)->implode(', ').'.';
        }
        $status .= ' Share the join link below with anyone who did not receive the message.';

        $response = back()->with('status', $status);

        if ($failed->isNotEmpty()) {
            $response->with('error', 'Could not deliver via '.$failed->map(fn ($channel) => $labels[$channel] ?? $channel)->implode(', ').'. Check the contact details or share the link manually.');
        }

        return $response;
    }
}
