<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMediaPublicationIdentity extends Model
{
    protected $table = 'social_publication_identities';

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
