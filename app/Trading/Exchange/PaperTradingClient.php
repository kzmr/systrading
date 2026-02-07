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
    private array $limitOrders = [];
    private GMOCoinClient $realDataClient;

    public function __construct()
    {
        // 初期残高を設定（USDTで10,000ドル）
        $this->balance = Cache::get('paper_balance', [
            'USDT' => 10000.0,
        ]);

        $this->positions = Cache::get('paper_positions', []);
        $this->limitOrders = Cache::get('paper_limit_orders', []);

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

        // 手数料をシミュレート（0.05% taker fee）
        $fee = $cost * 0.0005;

        if ($this->balance['USDT'] < ($cost + $fee)) {
            return [
                'success' => false,
                'message' => '残高不足',
                'balance' => $this->balance['USDT'],
                'required' => $cost + $fee,
            ];
        }

        // 残高を減らす（手数料含む）
        $this->balance['USDT'] -= ($cost + $fee);

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
            'fee' => $fee,
            'cost' => $cost,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function sell(string $symbol, float $quantity, ?float $price = null): array
    {
        $executionPrice = $price ?? $this->getCurrentPrice($symbol);
        $revenue = $quantity * $executionPrice;

        // 手数料をシミュレート（0.05% taker fee）
        $fee = $revenue * 0.0005;

        // ロングポジションから売却を試みる
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

        if ($sold) {
            // ロングポジションを決済した場合、残高を増やす（手数料を引く）
            $this->balance['USDT'] += ($revenue - $fee);
            $this->saveState();

            return [
                'success' => true,
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executionPrice,
                'fee' => $fee,
                'revenue' => $revenue,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        // ロングポジションがない場合は、ショート新規エントリーとして処理
        // ペーパートレードでは実際の資金は不要（仮想取引）
        // ポジション管理はOrderExecutorとPositionモデルで行う
        $this->saveState();

        return [
            'success' => true,
            'symbol' => $symbol,
            'quantity' => $quantity,
            'price' => $executionPrice,
            'fee' => $fee,
            'revenue' => $revenue,
            'timestamp' => now()->toIso8601String(),
            'type' => 'short_entry', // ショート新規エントリーであることを示す
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

    public function getCurrentPrice(string $symbol): float
    {
        $marketData = $this->getMarketData($symbol, 1);
        return end($marketData['prices']);
    }

    private function saveState(): void
    {
        Cache::put('paper_balance', $this->balance, now()->addDays(30));
        Cache::put('paper_positions', $this->positions, now()->addDays(30));
        Cache::put('paper_limit_orders', $this->limitOrders, now()->addDays(30));
    }

    public function getSpread(string $symbol): float
    {
        // GMOCoinから実際のスプレッドを取得
        return $this->realDataClient->getSpread($symbol);
    }

    /**
     * 注文をキャンセル
     */
    public function cancelOrder(string $orderId): array
    {
        if (isset($this->limitOrders[$orderId])) {
            unset($this->limitOrders[$orderId]);
            $this->saveState();

            return [
                'success' => true,
                'order_id' => $orderId,
            ];
        }

        return [
            'success' => false,
            'order_id' => $orderId,
            'message' => 'Order not found',
        ];
    }

    /**
     * 注文状態を取得
     * ペーパートレードでは、指値注文が約定可能かどうかをチェック
     */
    public function getOrderStatus(string $orderId): array
    {
        if (!isset($this->limitOrders[$orderId])) {
            return [
                'status' => 'NOT_FOUND',
                'order_id' => $orderId,
            ];
        }

        $order = $this->limitOrders[$orderId];
        $currentPrice = $this->getCurrentPrice($order['symbol']);

        // 約定判定：
        // SELL指値: 現在価格 >= 指値価格 で約定
        // BUY指値: 現在価格 <= 指値価格 で約定
        $shouldExecute = false;
        if ($order['side'] === 'SELL' && $currentPrice >= $order['price']) {
            $shouldExecute = true;
        } elseif ($order['side'] === 'BUY' && $currentPrice <= $order['price']) {
            $shouldExecute = true;
        }

        if ($shouldExecute) {
            // 約定処理
            $this->limitOrders[$orderId]['status'] = 'EXECUTED';
            $this->limitOrders[$orderId]['executedPrice'] = $order['price'];
            $this->saveState();

            return [
                'status' => 'EXECUTED',
                'order_id' => $orderId,
                'side' => $order['side'],
                'price' => $order['price'],
                'size' => $order['size'],
                'executedSize' => $order['size'],
            ];
        }

        return [
            'status' => 'WAITING',
            'order_id' => $orderId,
            'side' => $order['side'],
            'price' => $order['price'],
            'size' => $order['size'],
            'executedSize' => 0,
        ];
    }

    /**
     * 指値注文を登録（内部用）
     */
    public function registerLimitOrder(string $symbol, string $side, float $quantity, float $price): string
    {
        $orderId = 'paper_' . uniqid();
        $this->limitOrders[$orderId] = [
            'symbol' => $symbol,
            'side' => $side,
            'size' => $quantity,
            'price' => $price,
            'status' => 'WAITING',
            'timestamp' => now()->toIso8601String(),
        ];
        $this->saveState();

        return $orderId;
    }

    /**
     * 注文IDから約定情報を取得
     */
    public function getExecutionsByOrderId(string $orderId): array
    {
        if (!isset($this->limitOrders[$orderId])) {
            return [];
        }

        $order = $this->limitOrders[$orderId];

        if ($order['status'] !== 'EXECUTED') {
            return [];
        }

        // ペーパートレードでは手数料を0.01% (Maker rebate) としてシミュレート
        $fee = $order['price'] * $order['size'] * -0.0001;

        return [
            [
                'price' => $order['executedPrice'] ?? $order['price'],
                'size' => $order['size'],
                'fee' => $fee,
            ],
        ];
    }
}
