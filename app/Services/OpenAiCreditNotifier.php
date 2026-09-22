<?php

namespace App\Services;

use App\Notifications\OpenAiCreditAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class OpenAiCreditNotifier
{
    public function report(\Throwable $exception): void
    {
        $message = $exception->getMessage();
        if (! Str::contains(Str::lower($message), ['no credits remaining', 'credit_balance_exhausted'])) {
            return;
        }

        $email = trim((string) config('legatus.super_admin_email'));
        if ($email === '') {
            return;
        }

        if (! Cache::add('openai-credit-exhausted-alert', true, now()->addDay())) {
            return;
        }

        try {
            Notification::route('mail', $email)->notify(
                new OpenAiCreditAlert(Str::limit($message, 300)),
            );
        } catch (\Throwable $notificationError) {
            Cache::forget('openai-credit-exhausted-alert');
            Log::error('Could not send the OpenAI credit exhaustion alert.', [
                'exception' => $notificationError::class,
            ]);
        }
    }
}
