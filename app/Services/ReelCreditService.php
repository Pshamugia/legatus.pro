<?php

namespace App\Services;

use App\Models\AiReel;
use App\Models\Organization;
use App\Models\ReelCreditLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReelCreditService
{
    public function balance(Organization $organization): int
    {
        return (int) ReelCreditLedger::query()
            ->where('organization_id', $organization->id)
            ->sum('amount');
    }

    public function grantPurchase(Organization $organization, int $quantity, string $transactionId, array $metadata = []): void
    {
        if ($quantity < 1) {
            throw new \RuntimeException('A Reel credit purchase must contain at least one credit.');
        }

        ReelCreditLedger::firstOrCreate(
            ['reference' => 'paddle:'.$transactionId],
            [
                'organization_id' => $organization->id,
                'amount' => $quantity,
                'type' => 'purchase',
                'metadata' => $metadata,
            ],
        );
    }

    public function debit(Organization $organization, int $quantity, string $reference, array $metadata = []): void
    {
        DB::transaction(function () use ($organization, $quantity, $reference, $metadata): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            if ($this->balance($organization) < $quantity) {
                throw ValidationException::withMessages([
                    'credits' => "You need {$quantity} Reel credits to continue.",
                ]);
            }
            ReelCreditLedger::create([
                'organization_id' => $organization->id,
                'amount' => -$quantity,
                'type' => 'debit',
                'reference' => $reference,
                'metadata' => $metadata,
            ]);
        }, 3);
    }

    public function refund(AiReel $reel, string $reason): void
    {
        DB::transaction(function () use ($reel, $reason): void {
            $locked = AiReel::query()->whereKey($reel->id)->lockForUpdate()->firstOrFail();
            if ($locked->credit_refunded_at) {
                return;
            }
            ReelCreditLedger::firstOrCreate(
                ['reference' => 'reel-refund:'.$locked->id],
                [
                    'organization_id' => $locked->agent()->value('organization_id'),
                    'amount' => 1,
                    'type' => 'refund',
                    'metadata' => ['reel_id' => $locked->id, 'reason' => $reason],
                ],
            );
            $locked->update(['credit_refunded_at' => now()]);
        }, 3);
    }
}
