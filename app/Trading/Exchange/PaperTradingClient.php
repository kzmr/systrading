<?php

namespace App\Trading\Exchange;

use Illuminate\Support\Facades\Cache;

/**
 * ペーパートレーディング用のクライアント（仮想取引）
 * 実際のGMOCoin価格データを使用してシミュレーション
 */
class PaperTradingClient implements ExchangeClient
{
    private array $balance;
    private array $positions = [];
    private GMOCoinClient $realDataClient;

    public function __construct()
    {
        // 初期残高を設定（USDTで10,000ドル）
        $this->balance = Cache::get('paper_balance', [
            'USDT' => 10000.0,
        ]);

        $this->positions = Cache::get('paper_positions', []);

        // 実際の価格データ取得用のクライアント
        $this->realDataClient = new GMOCoinClient();
    }

    public function getMarketData(string $symbol, int $limit = 100): array
    {
        // GMOCoinから実際の価格データを取得
        return $this->realDataClient->getMarketData($symbol, $limit);
    }

    public function buy(string $symbol, float $quantity, ?float $price = null): array
    {
        $executionPrice = $price ?? $this->getCurrentPrice($symbol);
        $cost = $quantity * $executionPrice;

        if ($this->balance['USDT'] < $cost) {
            return [
                'success' => false,
                'message' => '残高不足',
                'balance' => $this->balance['USDT'],
                'required' => $cost,
            ];
        }

        // 残高を減らす
        $this->balance['USDT'] -= $cost;

        // ポジションを追加
        $this->positions[] = [
            'id' => uniqid(),
            'symbol' => $symbol,
            'side' => 'buy',
            'quantity' => $quantity,
            'entry_price' => $executionPrice,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->saveState();

        return [
            'success' => true,
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $executionPrice,
            'cost' => $cost,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function sell(string $symbol, float $quantity, ?float $price = null): array
    {
        $executionPrice = $price ?? $this->getCurrentPrice($symbol);
        $revenue = $quantity * $executionPrice;

        // ポジションから売却
        $sold = false;
        foreach ($this->positions as $key => $position) {
            if ($position['symbol'] === $symbol && $position['quantity'] >= $quantity) {
                $this->positions[$key]['quantity'] -= $quantity;
                if ($this->positions[$key]['quantity'] == 0) {
                    unset($this->positions[$key]);
                }
                $sold = true;
                break;
            }
        }

        if (!$sold) {
            return [
                'success' => false,
                'message' => '売却可能なポジションがありません',
            ];
        }

        // 残高を増やす
        $this->balance['USDT'] += $revenue;

        $this->saveState();

        return [
            'success' => true,
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $executionPrice,
            'revenue' => $revenue,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function getBalance(): array
    {
        return $this->balance;
    }

    public function getOpenPositions(): array
    {
        return array_values($this->positions);
    }

    private function getCurrentPrice(string $symbol): float
    {
        $marketData = $this->getMarketData($symbol, 1);
        return end($marketData['prices']);
    }

    private function saveState(): void
    {
        Cache::put('paper_balance', $this->balance, now()->addDays(30));
        Cache::put('paper_positions', $this->positions, now()->addDays(30));
    }

    public function getSpread(string $symbol): float
    {
        // GMOCoinから実際のスプレッドを取得
        return $this->realDataClient->getSpread($symbol);
    }
}
