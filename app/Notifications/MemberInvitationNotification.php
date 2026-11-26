<?php

namespace App\Notifications;

use App\Models\Group;
use App\Models\Member;
use App\Models\MemberInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly MemberInvitation $invitation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $group = Group::withoutGlobalScopes()->find($this->invitation->group_id);
        $inviter = Member::withoutGlobalScopes()->find($this->invitation->inviter_member_id);

        $groupName = $group?->name ?? 'our savings group';
        $message = (new MailMessage)
            ->subject('You are invited to join '.$groupName)
            ->greeting('Hello!')
            ->line(($inviter?->full_name ?? 'A group member').' has invited you to join '.$groupName.' on Chama Core.');

        if ($this->invitation->note) {
            $message->line('Message from '.($inviter?->first_name ?? 'the inviter').': '.$this->invitation->note);
        }

        $message->action('Complete your application', $this->invitation->joinUrl());

        if ($this->invitation->expires_at) {
            $message->line('This invitation expires on '.$this->invitation->expires_at->format('d M Y').'.');
        }

        return $message->salutation('Chama Core');
    }
}
