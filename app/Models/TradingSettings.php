<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingSettings extends Model
{
    protected $fillable = [
        'name',
        'symbol',
        'strategy',
        'parameters',
        'is_active',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_active' => 'boolean',
    ];
}
