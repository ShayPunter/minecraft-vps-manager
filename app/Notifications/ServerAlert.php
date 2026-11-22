<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServerAlert extends Notification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Minecraft VPS Manager: '.$this->subject)
            ->line($this->message)
            ->action('Open the servers', route('admin.servers.index'));
    }
}
