<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingProfile extends Model
{
    protected $guarded = [];

    protected $casts = ['preferences' => 'array'];
}
