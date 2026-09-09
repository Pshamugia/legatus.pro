<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMediaPublicationCycle extends Model
{
    protected $table = 'social_publication_cycles';

    protected $guarded = [];

    protected $casts = ['current_cycle' => 'integer'];
}
