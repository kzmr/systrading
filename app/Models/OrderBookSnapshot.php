<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 板情報のスナップショット
 *
 * 価格・出来高では見つからなかった優位性を探すための情報源。
 * 全量ではなく分析用の集計値を保持する。
 */
class OrderBookSnapshot extends Model
{
    protected $fillable = [
        'symbol',
        'recorded_at',
        'best_bid',
        'best_ask',
        'mid_price',
        'spread_percent',
        'bid_size_top5',
        'ask_size_top5',
        'bid_size_top20',
        'ask_size_top20',
        'bid_size_within_01pct',
        'ask_size_within_01pct',
        'bid_size_within_05pct',
        'ask_size_within_05pct',
        'imbalance_top5',
        'imbalance_top20',
        'imbalance_within_05pct',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'best_bid' => 'float',
        'best_ask' => 'float',
        'mid_price' => 'float',
        'spread_percent' => 'float',
        'bid_size_top5' => 'float',
        'ask_size_top5' => 'float',
        'bid_size_top20' => 'float',
        'ask_size_top20' => 'float',
        'bid_size_within_01pct' => 'float',
        'ask_size_within_01pct' => 'float',
        'bid_size_within_05pct' => 'float',
        'ask_size_within_05pct' => 'float',
        'imbalance_top5' => 'float',
        'imbalance_top20' => 'float',
        'imbalance_within_05pct' => 'float',
    ];
}
