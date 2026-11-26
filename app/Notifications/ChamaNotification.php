<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChamaNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly string $key = 'general',
        public readonly bool $sendMail = true,
        public readonly ?string $actionUrl = null,
        public readonly bool $sendDatabase = true,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($this->sendDatabase) {
            $channels[] = 'database';
        }

        if ($this->sendMail && ! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'key' => $this->key,
            'data' => $this->data,
            'action_url' => $this->actionUrl,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.($notifiable->name ?? 'there'))
            ->line($this->body);

        if ($this->actionUrl) {
            $message->action('Open Chama Core', $this->actionUrl);
        }

        return $message->salutation('Chama Core');
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
