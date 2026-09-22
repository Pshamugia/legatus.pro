<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OpenAiCreditAlert extends Notification
{
    public function __construct(public readonly string $providerError) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Urgent: Legatus OpenAI API credit is exhausted')
            ->greeting('Legatus OpenAI billing alert')
            ->line('OpenAI rejected a Legatus request because the API account has no credits remaining.')
            ->line($this->providerError)
            ->action('Open OpenAI billing', 'https://platform.openai.com/settings/organization/billing/overview')
            ->line('Add credit before more customer messages are affected. This alert is limited to one email per 24 hours.');
    }
}
