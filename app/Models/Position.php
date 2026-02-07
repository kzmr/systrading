<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'symbol',
        'trading_settings_id',
        'side',
        'quantity',
        'entry_price',
        'entry_fee',
        'trailing_stop_price',
        'exit_order_id',
        'exit_order_price',
        'exit_price',
        'exit_fee',
        'status',
        'profit_loss',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'entry_price' => 'decimal:8',
        'entry_fee' => 'decimal:8',
        'trailing_stop_price' => 'decimal:8',
        'exit_order_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'exit_fee' => 'decimal:8',
        'profit_loss' => 'decimal:8',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * 合計手数料を取得
     */
    public function getTotalFeeAttribute(): float
    {
        return (float)($this->entry_fee ?? 0) + (float)($this->exit_fee ?? 0);
    }

    /**
     * 純損益（手数料控除後）を取得
     */
    public function getNetProfitLossAttribute(): float
    {
        return (float)($this->profit_loss ?? 0) - $this->total_fee;
    }
}
