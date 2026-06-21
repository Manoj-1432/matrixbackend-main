<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'image_url', 'link', 'is_active', 'sort_order'];

    /** @var array<string, string> */
    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];
}
