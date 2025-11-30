<?php

namespace App\Trading\Strategy;

use Illuminate\Support\Facades\Log;

/**
 * 高値安値ブレイクアウト戦略
 *
 * 一定期間の高値を上抜けたら買い、安値を下抜けたら売り
 */
class HighLowBreakoutStrategy extends TradingStrategy
{
    /**
     * 市場データを分析してトレーディングシグナルを生成
     *
     * @param array $marketData 市場データ（prices配列を含む）
     * @return array ['action' => 'buy'|'sell'|'hold', 'quantity' => float, 'price' => float|null]
     */
    public function analyze(array $marketData): array
    {
        $params = $this->getParameters();
        $lookbackPeriod = $params['lookback_period'] ?? 20;
        $breakoutThreshold = $params['breakout_threshold'] ?? 0.1;

        $prices = $marketData['prices'];
        $symbol = $marketData['symbol'];

        // 価格データが不足している場合はホールド
        if (count($prices) < $lookbackPeriod + 1) {
            Log::info('Insufficient data for High-Low Breakout', [
                'symbol' => $symbol,
                'available' => count($prices),
                'required' => $lookbackPeriod + 1,
            ]);

            return [
                'action' => 'hold',
                'quantity' => 0,
                'price' => null,
            ];
        }

        // 現在価格（最新）
        $currentPrice = end($prices);

        // 過去N本の価格データ（現在を除く）
        $historicalPrices = array_slice($prices, -($lookbackPeriod + 1), $lookbackPeriod);

        // 過去N本の最高値と最安値を計算
        $highestHigh = max($historicalPrices);
        $lowestLow = min($historicalPrices);

        // ブレイクアウト閾値を計算
        $buyThreshold = $highestHigh * (1 + $breakoutThreshold / 100);
        $sellThreshold = $lowestLow * (1 - $breakoutThreshold / 100);

        Log::info('High-Low Breakout Analysis', [
            'symbol' => $symbol,
            'current_price' => $currentPrice,
            'highest_high' => $highestHigh,
            'lowest_low' => $lowestLow,
            'buy_threshold' => $buyThreshold,
            'sell_threshold' => $sellThreshold,
            'lookback_period' => $lookbackPeriod,
        ]);

        // 高値ブレイクアウト → 買いシグナル
        if ($currentPrice > $buyThreshold) {
            Log::info('HIGH BREAKOUT - BUY SIGNAL', [
                'symbol' => $symbol,
                'current_price' => $currentPrice,
                'buy_threshold' => $buyThreshold,
                'breakout' => ($currentPrice - $highestHigh) / $highestHigh * 100 . '%',
            ]);

            return [
                'action' => 'buy',
                'quantity' => config('trading.defaults.trade_size', 0.01),
                'price' => null, // 成行注文
            ];
        }

        // 安値ブレイクダウン → ショート売りシグナル
        if ($currentPrice < $sellThreshold) {
            Log::info('LOW BREAKOUT - SHORT SELL SIGNAL', [
                'symbol' => $symbol,
                'current_price' => $currentPrice,
                'sell_threshold' => $sellThreshold,
                'breakout' => ($lowestLow - $currentPrice) / $lowestLow * 100 . '%',
            ]);

            return [
                'action' => 'short',
                'quantity' => config('trading.defaults.trade_size', 0.01),
                'price' => null, // 成行注文
            ];
        }

        // どちらでもない場合はホールド
        return [
            'action' => 'hold',
            'quantity' => 0,
            'price' => null,
        ];
    }
}
