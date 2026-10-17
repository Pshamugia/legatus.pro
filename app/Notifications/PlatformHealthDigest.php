<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformHealthDigest extends Notification
{
    /**
     * @param  array<int, array{title: string, summary: string|null}>  $opened
     * @param  array<int, array{title: string, summary: string|null}>  $resolved
     */
    public function __construct(
        public readonly array $opened,
        public readonly array $resolved,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hasFailures = $this->opened !== [];
        $mail = (new MailMessage)
            ->subject($hasFailures
                ? 'Urgent: Legatus system health needs attention'
                : 'Resolved: Legatus system health recovered')
            ->greeting($hasFailures ? 'Legatus needs attention' : 'Legatus recovery update');

        foreach ($this->opened as $incident) {
            $mail->line('NEW · '.$incident['title'].($incident['summary'] ? ' — '.$incident['summary'] : ''));
        }
        foreach ($this->resolved as $incident) {
            $mail->line('RESOLVED · '.$incident['title']);
        }

        return $mail
            ->action('Open System Health', url('/super-admin'))
            ->line('Unchanged open incidents do not send duplicate email.');
    }
}
