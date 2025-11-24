<?php

namespace App\Trading\Executor;

use App\Models\Position;
use App\Models\TradingLog;
use App\Trading\Exchange\ExchangeClient;
use App\Trading\Strategy\TradingStrategy;
use Illuminate\Support\Facades\Log;

/**
 * 注文実行を管理するクラス
 */
class OrderExecutor
{
    private ExchangeClient $exchangeClient;
    private TradingStrategy $strategy;

    public function __construct(ExchangeClient $exchangeClient, TradingStrategy $strategy)
    {
        $this->exchangeClient = $exchangeClient;
        $this->strategy = $strategy;
    }

    /**
     * トレーディングロジックを実行
     *
     * @param string $symbol 通貨ペア
     * @return array 実行結果
     */
    public function execute(string $symbol): array
    {
        try {
            // 1. 市場データを取得
            $marketData = $this->exchangeClient->getMarketData($symbol);

            // 2. ストラテジーで分析
            $signal = $this->strategy->analyze($marketData);

            // 3. シグナルに基づいて注文実行
            $result = $this->processSignal($symbol, $signal);

            // 4. ログを記録
            $this->logExecution($symbol, $signal, $result);

            return $result;
        } catch (\Exception $e) {
            Log::error('Order execution failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            TradingLog::create([
                'symbol' => $symbol,
                'action' => 'error',
                'message' => $e->getMessage(),
                'executed_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * シグナルを処理して注文実行
     */
    private function processSignal(string $symbol, array $signal): array
    {
        if ($signal['action'] === 'hold') {
            return [
                'success' => true,
                'action' => 'hold',
                'message' => 'ホールド - 取引なし',
            ];
        }

        if ($signal['action'] === 'buy') {
            // 新規エントリー時はスプレッドをチェック
            $maxSpread = config('trading.defaults.max_spread', 500);
            $currentSpread = $this->exchangeClient->getSpread($symbol);

            if ($currentSpread > $maxSpread) {
                Log::warning('Spread too wide for entry', [
                    'symbol' => $symbol,
                    'current_spread' => $currentSpread,
                    'max_spread' => $maxSpread,
                ]);

                return [
                    'success' => false,
                    'action' => 'buy_rejected',
                    'message' => "スプレッド超過 ({$currentSpread}円 > {$maxSpread}円) - エントリー見送り",
                ];
            }

            $result = $this->exchangeClient->buy($symbol, $signal['quantity'], $signal['price']);

            if ($result['success']) {
                // ポジションを記録
                Position::create([
                    'symbol' => $symbol,
                    'side' => 'long',
                    'quantity' => $signal['quantity'],
                    'entry_price' => $result['price'],
                    'status' => 'open',
                    'opened_at' => now(),
                ]);
            }

            return $result;
        }

        if ($signal['action'] === 'sell') {
            $result = $this->exchangeClient->sell($symbol, $signal['quantity'], $signal['price']);

            if ($result['success']) {
                // ポジションをクローズ
                $position = Position::where('symbol', $symbol)
                    ->where('status', 'open')
                    ->orderBy('opened_at', 'desc')
                    ->first();

                if ($position) {
                    $position->update([
                        'exit_price' => $result['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => ($result['price'] - $position->entry_price) * $position->quantity,
                    ]);
                }
            }

            return $result;
        }

        return [
            'success' => false,
            'message' => '不明なアクション: ' . $signal['action'],
        ];
    }

    /**
     * 実行ログを記録
     */
    private function logExecution(string $symbol, array $signal, array $result): void
    {
        TradingLog::create([
            'symbol' => $symbol,
            'action' => $signal['action'],
            'quantity' => $signal['quantity'] ?? 0,
            'price' => $signal['price'] ?? 0,
            'result' => json_encode($result),
            'message' => $result['message'] ?? 'OK',
            'executed_at' => now(),
        ]);
    }
}
