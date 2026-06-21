<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slot extends Model
{
    protected $fillable = [
        'day',
        'start_time',
        'end_time',
        'max_bookings',
        'status',
    ];

    protected $casts = [
        'max_bookings' => 'integer',
    ];

    /** Scopes for active slots (used by public booking logic). */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Scopes for a specific day. */
    public function scopeForDay($query, string $day)
    {
        return $query->where('day', $day);
    }
}
