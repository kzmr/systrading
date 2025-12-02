<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    protected $table = 'price_history';

    protected $fillable = [
        'symbol',
        'price',
        'recorded_at',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'recorded_at' => 'datetime',
    ];
}
