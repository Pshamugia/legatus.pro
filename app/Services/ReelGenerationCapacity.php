<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ReelGenerationCapacity
{
    private ?int $balance = null;

    public function __construct(private readonly RunwayClient $runway) {}

    public function maxReels(int $duration): int
    {
        $rate = (int) config('services.runway.multi_shot_credit_rate_per_second', 13);
        if ($rate <= 0 || ! in_array($duration, [5, 10, 15], true)) {
            return 0;
        }

        if ($this->balance === null) {
            try {
                $balance = $this->runway->organization()['creditBalance'] ?? null;
                $this->balance = is_numeric($balance) ? max(0, (int) $balance) : 0;
            } catch (\Throwable) {
                $this->balance = 0;
            }
        }

        return intdiv($this->balance, $rate * $duration);
    }

    public function ensureAvailable(int $duration, int $count = 1): void
    {
        if ($this->maxReels($duration) < $count) {
            throw ValidationException::withMessages([
                'generation' => 'Video generation is temporarily unavailable on our side for this request. Your Reel credits have not been charged. Buying more Reel credits will not resolve this; please try again later.',
            ]);
        }
    }
}
