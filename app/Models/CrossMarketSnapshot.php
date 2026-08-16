<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 市場横断のスナップショット
 *
 * 日本プレミアム = BTC/JPY ÷ (BTC/USD × USD/JPY) - 1
 * 「価格を当てる」のではなく「市場間の関係の歪み」を捉えるためのデータ。
 */
class CrossMarketSnapshot extends Model
{
    protected $fillable = [
        'recorded_at',
        'gmo_bid', 'gmo_ask', 'gmo_mid',
        'bitflyer_mid', 'coincheck_mid', 'bitbank_mid',
        'btc_usd', 'usd_jpy', 'fx_age_seconds',
        'fair_value_jpy', 'premium_percent',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'gmo_bid' => 'float', 'gmo_ask' => 'float', 'gmo_mid' => 'float',
        'bitflyer_mid' => 'float', 'coincheck_mid' => 'float', 'bitbank_mid' => 'float',
        'btc_usd' => 'float', 'usd_jpy' => 'float', 'fx_age_seconds' => 'integer',
        'fair_value_jpy' => 'float', 'premium_percent' => 'float',
    ];

    /**
     * 国内取引所間の最大価格差（%）
     *
     * 取引所をまたいだ裁定機会の指標。
     */
    public function domesticSpreadPercent(): ?float
    {
        $mids = array_filter([
            $this->gmo_mid, $this->bitflyer_mid, $this->coincheck_mid, $this->bitbank_mid,
        ]);

        if (count($mids) < 2) {
            return null;
        }

        $min = min($mids);

        return $min > 0 ? (max($mids) / $min - 1) * 100 : null;
    }

    /**
     * 為替レートが取引時間内のものか
     *
     * FX市場は土日に閉じるため、暗号資産の値動きとの間に非対称が生じる。
     * 分析時にこの期間を区別できるようにする。
     */
    public function hasFreshFx(int $maxAgeSeconds = 900): bool
    {
        return $this->fx_age_seconds !== null && $this->fx_age_seconds <= $maxAgeSeconds;
    }
}
