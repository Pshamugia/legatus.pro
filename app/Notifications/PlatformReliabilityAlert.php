<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformReliabilityAlert extends Notification
{
    /** @param array<int, string> $failedChecks */
    public function __construct(
        public readonly string $mode,
        public readonly bool $healthy,
        public readonly array $failedChecks,
        public readonly string $checkedAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->healthy
                ? 'Resolved: Legatus '.$this->mode.' reliability recovered'
                : 'Urgent: Legatus '.$this->mode.' reliability check failed')
            ->greeting($this->healthy ? 'Legatus is healthy again' : 'Legatus needs attention')
            ->line($this->healthy
                ? 'The scheduled platform reliability check recovered after the previous failure.'
                : 'The scheduled platform reliability check detected a failure.')
            ->line('Checked at: '.$this->checkedAt);

        if (! $this->healthy && $this->failedChecks !== []) {
            $mail->line('Failed checks: '.implode(', ', $this->failedChecks));
        }

        return $mail
            ->action('Open Super Admin', url('/super-admin'))
            ->line('You will receive another reliability email only when the health state changes.');
    }
}
