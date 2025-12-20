<?php

namespace App\Trading\Strategy;

use App\Models\TradingSettings;

/**
 * トレーディング戦略の基底クラス
 */
abstract class TradingStrategy
{
    protected TradingSettings $settings;

    public function __construct(TradingSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * 市場データを分析して取引シグナルを生成
     *
     * @param array $marketData 市場データ（価格、出来高など）
     * @return array ['action' => 'buy'|'sell'|'hold', 'quantity' => float, 'price' => float]
     */
    abstract public function analyze(array $marketData): array;

    /**
     * ストラテジーのパラメータを取得
     */
    public function getParameters(): array
    {
        return $this->settings->parameters ?? [];
    }

    /**
     * 戦略名を取得
     */
    public function getName(): string
    {
        return $this->settings->name ?? class_basename(static::class);
    }

    /**
     * 戦略設定IDを取得
     */
    public function getSettingsId(): int
    {
        return $this->settings->id;
    }


    /**
     * トレンド方向を判定
     *
     * 移動平均線からの乖離率でトレンドを判定する
     * - 現在価格がMAより閾値以上高い → 上昇トレンド
     * - 現在価格がMAより閾値以上低い → 下落トレンド
     * - それ以外 → レンジ相場
     *
     * @param array $prices 価格配列
     * @param int $period MA期間（デフォルト60）
     * @param float $threshold 閾値パーセント（デフォルト0.3%）
     * @return string 'up'|'down'|'range'|'unknown'
     */
    protected function detectTrend(array $prices, int $period = 60, float $threshold = 0.3): string
    {
        if (count($prices) < $period) {
            return 'unknown';
        }

        // 移動平均を計算
        $ma = array_sum(array_slice($prices, -$period)) / $period;
        $currentPrice = end($prices);

        // MAからの乖離率（%）
        $deviation = ($currentPrice - $ma) / $ma * 100;

        if ($deviation > $threshold) {
            return 'up';
        } elseif ($deviation < -$threshold) {
            return 'down';
        }

        return 'range';
    }
}
