<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'title',
        'description',
        'code',
        'discount_type',
        'discount_value',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'discount_value' => 'decimal:2',
    ];
}
