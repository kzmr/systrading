<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingLog extends Model
{
    protected $fillable = [
        'symbol',
        'action',
        'quantity',
        'price',
        'result',
        'message',
        'executed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'price' => 'decimal:8',
        'executed_at' => 'datetime',
    ];
}
