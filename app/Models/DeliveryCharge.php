<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryCharge extends Model
{
    protected $fillable = [
        'from_distance',
        'to_distance',
        'charge',
        'status',
    ];

    protected $casts = [
        'from_distance' => 'decimal:2',
        'to_distance'   => 'decimal:2',
        'charge'        => 'decimal:2',
    ];
}
