<?php

namespace App\Trading\Strategy;

/**
 * 単純移動平均線戦略
 * 短期MAが長期MAを上抜け→買い、下抜け→売り
 */
class SimpleMovingAverageStrategy extends TradingStrategy
{
    public function analyze(array $marketData): array
    {
        $params = $this->getParameters();
        $shortPeriod = $params['short_period'] ?? 5;
        $longPeriod = $params['long_period'] ?? 20;

        // 価格データから移動平均を計算
        $prices = $marketData['prices'] ?? [];

        if (count($prices) < $longPeriod) {
            return ['action' => 'hold', 'quantity' => 0, 'price' => 0];
        }

        $shortMA = $this->calculateMA($prices, $shortPeriod);
        $longMA = $this->calculateMA($prices, $longPeriod);

        $currentPrice = end($prices);
        $tradeSize = $params['trade_size'] ?? 0.01;

        // ゴールデンクロス
        if ($shortMA > $longMA && $this->previousCross($prices, $shortPeriod, $longPeriod) === 'golden') {
            return [
                'action' => 'buy',
                'quantity' => $tradeSize,
                'price' => $currentPrice
            ];
        }

        // デッドクロス
        if ($shortMA < $longMA && $this->previousCross($prices, $shortPeriod, $longPeriod) === 'dead') {
            return [
                'action' => 'sell',
                'quantity' => $tradeSize,
                'price' => $currentPrice
            ];
        }

        return ['action' => 'hold', 'quantity' => 0, 'price' => $currentPrice];
    }

    private function calculateMA(array $prices, int $period): float
    {
        $relevantPrices = array_slice($prices, -$period);
        return array_sum($relevantPrices) / count($relevantPrices);
    }

    private function previousCross(array $prices, int $shortPeriod, int $longPeriod): ?string
    {
        if (count($prices) < $longPeriod + 1) {
            return null;
        }

        // 現在
        $currentShort = $this->calculateMA($prices, $shortPeriod);
        $currentLong = $this->calculateMA($prices, $longPeriod);

        // 1つ前
        $prevPrices = array_slice($prices, 0, -1);
        $prevShort = $this->calculateMA($prevPrices, $shortPeriod);
        $prevLong = $this->calculateMA($prevPrices, $longPeriod);

        if ($prevShort <= $prevLong && $currentShort > $currentLong) {
            return 'golden';
        }

        if ($prevShort >= $prevLong && $currentShort < $currentLong) {
            return 'dead';
        }

        return null;
    }
}
