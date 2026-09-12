<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReelCreditLedger extends Model
{
    protected $table = 'reel_credit_ledger';

    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
