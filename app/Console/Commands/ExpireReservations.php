<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireReservations extends Command
{
    protected $signature = 'legatus:expire-reservations';

    protected $description = 'Release expired pending product reservations';

    public function handle(): int
    {
        $now = now();
        $count = DB::transaction(function () use ($now): int {
            $expired = Reservation::where('status', 'pending')
                ->where('expires_at', '<=', $now)
                ->lockForUpdate()
                ->get();

            if ($expired->isEmpty()) {
                return 0;
            }

            Reservation::whereKey($expired->modelKeys())->update(['status' => 'expired']);

            foreach ($expired->pluck('conversation_id')->unique() as $conversationId) {
                $conversation = Conversation::whereKey($conversationId)->lockForUpdate()->first();
                if (! $conversation || $conversation->outcome !== 'pending_reservation') {
                    continue;
                }

                $hasPendingHold = Reservation::where('conversation_id', $conversationId)
                    ->where('status', 'pending')
                    ->exists();

                if (! $hasPendingHold) {
                    $conversation->update([
                        'status' => 'closed',
                        'outcome' => 'reservation_expired',
                        'outcome_value' => 0,
                        'resolved_at' => $now,
                    ]);
                }
            }

            return $expired->count();
        });

        $this->info("Released {$count} expired reservation(s).");

        return self::SUCCESS;
    }
}
