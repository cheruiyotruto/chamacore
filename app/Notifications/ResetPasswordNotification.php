<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your Chama Core password')
            ->greeting('Hello '.$notifiable->name)
            ->line('We received a request to reset your password.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes.')
            ->line('If you did not request a reset, no action is required.')
            ->salutation('Chama Core');
    }
}
