<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Notifications\MemberInvitationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class InvitationService
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly WhatsAppGateway $whatsapp,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Member $inviter, array $data): MemberInvitation
    {
        $channels = array_values(array_intersect(
            MemberInvitation::CHANNELS,
            array_map('strval', (array) ($data['channels'] ?? []))
        ));

        $invitation = MemberInvitation::create([
            'group_id' => $inviter->group_id,
            'inviter_member_id' => $inviter->id,
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'phone' => filled($data['phone'] ?? null) ? $data['phone'] : null,
            'note' => filled($data['note'] ?? null) ? $data['note'] : null,
            'require_biometric' => (bool) ($data['require_biometric'] ?? false),
            'channels' => $channels,
            'status' => MemberInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(max(1, (int) ($data['expires_in'] ?? 14))),
        ]);

        $this->send($invitation);

        return $invitation;
    }

    /**
     * Deliver the invitation through every channel the inviter selected.
     *
     * @return array<string, array{status:string, reference:?string, error:?string}>
     */
    public function send(MemberInvitation $invitation): array
    {
        $delivery = [];

        foreach ((array) ($invitation->channels ?? []) as $channel) {
            $delivery[$channel] = match ($channel) {
                'email' => $this->sendEmail($invitation),
                'sms' => $this->sendSms($invitation),
                'whatsapp' => $this->sendWhatsApp($invitation),
                default => ['status' => 'skipped', 'reference' => null, 'error' => 'Unknown channel.'],
            };
        }

        $invitation->forceFill([
            'delivery' => $delivery,
            'sent_at' => now(),
        ])->saveQuietly();

        return $delivery;
    }

    /**
     * @return array{status:string, reference:?string, error:?string}
     */
    private function sendEmail(MemberInvitation $invitation): array
    {
        if (! $invitation->email) {
            return ['status' => 'skipped', 'reference' => null, 'error' => 'No email address supplied.'];
        }

        try {
            Notification::route('mail', $invitation->email)
                ->notify(new MemberInvitationNotification($invitation));

            return ['status' => 'sent', 'reference' => null, 'error' => null];
        } catch (Throwable $e) {
            Log::warning('invitation.email.failed', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reference' => null, 'error' => 'Email delivery failed.'];
        }
    }

    /**
     * @return array{status:string, reference:?string, error:?string}
     */
    private function sendSms(MemberInvitation $invitation): array
    {
        if (! $invitation->phone) {
            return ['status' => 'skipped', 'reference' => null, 'error' => 'No phone number supplied.'];
        }

        try {
            $result = $this->sms->send($invitation->phone, $this->message($invitation));

            return [
                'status' => $result['success'] ? 'sent' : 'failed',
                'reference' => $result['reference'],
                'error' => $result['error'],
            ];
        } catch (Throwable $e) {
            Log::warning('invitation.sms.failed', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reference' => null, 'error' => 'SMS delivery failed.'];
        }
    }

    /**
     * @return array{status:string, reference:?string, error:?string}
     */
    private function sendWhatsApp(MemberInvitation $invitation): array
    {
        if (! $invitation->phone) {
            return ['status' => 'skipped', 'reference' => null, 'error' => 'No phone number supplied.'];
        }

        try {
            $result = $this->whatsapp->send($invitation->phone, $this->message($invitation));

            return [
                'status' => $result['success'] ? 'sent' : 'failed',
                'reference' => $result['reference'],
                'error' => $result['error'],
            ];
        } catch (Throwable $e) {
            Log::warning('invitation.whatsapp.failed', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'reference' => null, 'error' => 'WhatsApp delivery failed.'];
        }
    }

    public function message(MemberInvitation $invitation): string
    {
        $group = Group::withoutGlobalScopes()->find($invitation->group_id);
        $inviter = Member::withoutGlobalScopes()->find($invitation->inviter_member_id);

        $message = sprintf(
            '%s invited you to join %s on Chama Core. Apply here: %s%s',
            $inviter?->first_name ?? 'A group member',
            $group?->name ?? 'our group',
            $invitation->joinUrl(),
            $invitation->expires_at ? ' (expires '.$invitation->expires_at->format('d M Y').')' : ''
        );

        $note = trim((string) $invitation->note);
        if ($note !== '') {
            $message .= ' '.$note;
        }

        return $message;
    }
}
