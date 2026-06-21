<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedRating extends Model
{
    protected $fillable = [
        'rating',
        'max_speed',
        'description',
        'status',
    ];
}

