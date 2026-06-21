<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelEfficiency extends Model
{
    protected $fillable = [
        'rating',
        'description',
        'status',
    ];
}

