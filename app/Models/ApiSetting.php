<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiSetting extends Model
{
    protected $fillable = [
        'key_name',
        'label',
        'description',
        'icon_type',
        'value',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'value'      => 'encrypted',
            'is_enabled' => 'boolean',
        ];
    }
}
