<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tyre extends Model
{
    protected $fillable = [
        'brand_id',
        'model',
        'size_id',
        'season_id',
        'tyre_type_id',
        'fuel_efficiency_id',
        'speed_rating_id',
        'price',
        'stock',
        'description',
        'status',
        'image_url',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function tyreType(): BelongsTo
    {
        return $this->belongsTo(TyreType::class);
    }

    public function fuelEfficiency(): BelongsTo
    {
        return $this->belongsTo(FuelEfficiency::class);
    }

    public function speedRating(): BelongsTo
    {
        return $this->belongsTo(SpeedRating::class);
    }
}
