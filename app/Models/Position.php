<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'symbol',
        'side',
        'quantity',
        'entry_price',
        'exit_price',
        'status',
        'profit_loss',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'entry_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'profit_loss' => 'decimal:8',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
