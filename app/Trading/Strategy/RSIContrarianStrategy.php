<?php

namespace App\Trading\Strategy;

use App\Models\Position;
use App\Models\TradingSettings;
use Illuminate\Support\Facades\Log;

/**
 * RSI逆張り戦略
 *
 * RSI（相対力指数）が極端な値になった時に逆張りでエントリーする戦略
 * - RSI < 30（売られすぎ）→ 買いシグナル
 * - RSI > 70（買われすぎ）→ ショートシグナル
 *
 * 決済条件:
 * - 買いポジション: RSI > 50 で利確
 * - ショートポジション: RSI < 50 で利確
 * - タイムアウト: max_hold_minutes 経過で強制決済
 *
 * クールダウン機能:
 * - RSI逆張り戦略で負けトレードが発生した場合、全通貨ペアで一定時間エントリーをスキップ
 * - デフォルト: 30分間
 */
class RSIContrarianStrategy extends TradingStrategy
{
    private ?float $currentRSI = null;

    /**
     * クールダウン中かどうかをチェック
     *
     * RSI逆張り戦略で負けトレードがあった場合、指定時間内はエントリーをスキップ
     * 全通貨ペアに適用（仮想通貨は相関が高いため）
     *
     * @param int $cooldownMinutes クールダウン時間（分）
     * @return bool クールダウン中の場合true
     */
    private function isInCooldown(int $cooldownMinutes): bool
    {
        if ($cooldownMinutes <= 0) {
            return false;
        }

        $cooldownThreshold = now()->subMinutes($cooldownMinutes);

        // RSI逆張り戦略の全設定IDを取得
        $rsiStrategyIds = TradingSettings::where('strategy', 'like', '%RSIContrarianStrategy%')
            ->pluck('id')
            ->toArray();

        if (empty($rsiStrategyIds)) {
            return false;
        }

        // 指定時間内に負けトレード（profit_loss < 0）があるかチェック
        // 通貨ペアによらず、RSI逆張り戦略全体でチェック
        $recentLoss = Position::where('status', 'closed')
            ->whereIn('trading_settings_id', $rsiStrategyIds)
            ->where('profit_loss', '<', 0)
            ->where('closed_at', '>=', $cooldownThreshold)
            ->first();

        if ($recentLoss) {
            Log::info('RSI Contrarian Cooldown Active', [
                'recent_loss_id' => $recentLoss->id,
                'recent_loss_symbol' => $recentLoss->symbol,
                'recent_loss_profit' => $recentLoss->profit_loss,
                'closed_at' => $recentLoss->closed_at,
                'cooldown_until' => $recentLoss->closed_at->addMinutes($cooldownMinutes)->format('Y-m-d H:i:s'),
            ]);
            return true;
        }

        return false;
    }

    /**
     * RSIを計算
     *
     * @param array $prices 価格配列
     * @param int $period 計算期間
     * @return float|null RSI値（0-100）、計算不可の場合はnull
     */
    private function calculateRSI(array $prices, int $period): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        // 価格変動を計算
        $gains = [];
        $losses = [];

        for ($i = 1; $i <= $period; $i++) {
            $change = $prices[count($prices) - $period - 1 + $i] - $prices[count($prices) - $period - 1 + $i - 1];

            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        // 損失が0の場合、RSIは100
        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return round($rsi, 2);
    }

    /**
     * 市場データを分析してトレーディングシグナルを生成
     *
     * @param array $marketData 市場データ（prices配列を含む）
     * @return array ['action' => 'buy'|'sell'|'short'|'hold', 'quantity' => float, 'price' => float|null]
     */
    public function analyze(array $marketData): array
    {
        $params = $this->getParameters();
        $rsiPeriod = $params['rsi_period'] ?? 14;
        $rsiOversold = $params['rsi_oversold'] ?? 30;
        $rsiOverbought = $params['rsi_overbought'] ?? 70;
        $cooldownMinutes = $params['cooldown_minutes'] ?? 30;

        $prices = $marketData['prices'];
        $symbol = $marketData['symbol'];

        // 価格データが不足している場合はホールド
        if (count($prices) < $rsiPeriod + 1) {
            Log::info('Insufficient data for RSI calculation', [
                'symbol' => $symbol,
                'available' => count($prices),
                'required' => $rsiPeriod + 1,
            ]);

            return [
                'action' => 'hold',
                'quantity' => 0,
                'price' => null,
            ];
        }

        // スプレッドチェック（DBから取得）
        $maxSpreadPercent = $params['max_spread'] ?? 0.1;
        if (isset($marketData['bid']) && isset($marketData['ask'])) {
            $bid = (float)$marketData['bid'];
            $ask = (float)$marketData['ask'];

            if ($bid > 0) {
                $spreadPercent = (($ask - $bid) / $bid) * 100;

                if ($spreadPercent > $maxSpreadPercent) {
                    Log::warning('Spread too wide - skipping entry', [
                        'symbol' => $symbol,
                        'bid' => $bid,
                        'ask' => $ask,
                        'spread_percent' => $spreadPercent,
                        'max_spread_percent' => $maxSpreadPercent,
                    ]);

                    return [
                        'action' => 'hold',
                        'quantity' => 0,
                        'price' => null,
                    ];
                }
            }
        }

        // 現在価格（最新）
        $currentPrice = end($prices);

        // RSIを計算
        $rsi = $this->calculateRSI($prices, $rsiPeriod);

        if ($rsi === null) {
            return [
                'action' => 'hold',
                'quantity' => 0,
                'price' => null,
            ];
        }

        // RSIを保存（決済判定で使用）
        $this->currentRSI = $rsi;

        Log::info('RSI Contrarian Analysis', [
            'symbol' => $symbol,
            'current_price' => $currentPrice,
            'rsi' => $rsi,
            'rsi_period' => $rsiPeriod,
            'oversold_threshold' => $rsiOversold,
            'overbought_threshold' => $rsiOverbought,
        ]);

        // クールダウンチェック（エントリーシグナル発生時のみ）
        $shouldBuy = $rsi < $rsiOversold;
        $shouldShort = $rsi > $rsiOverbought;

        if (($shouldBuy || $shouldShort) && $this->isInCooldown($cooldownMinutes)) {
            Log::info('RSI Contrarian Entry Skipped - Cooldown Active', [
                'symbol' => $symbol,
                'rsi' => $rsi,
                'would_be_action' => $shouldBuy ? 'buy' : 'short',
                'cooldown_minutes' => $cooldownMinutes,
            ]);

            return [
                'action' => 'hold',
                'quantity' => 0,
                'price' => null,
            ];
        }

        // RSI < 30（売られすぎ）→ 買いシグナル
        if ($shouldBuy) {
            Log::info('RSI OVERSOLD - BUY SIGNAL', [
                'symbol' => $symbol,
                'current_price' => $currentPrice,
                'rsi' => $rsi,
                'threshold' => $rsiOversold,
            ]);

            return [
                'action' => 'buy',
                'quantity' => $params['trade_size'] ?? 1,
                'price' => null, // 成行注文
            ];
        }

        // RSI > 70（買われすぎ）→ ショートシグナル
        if ($shouldShort) {
            Log::info('RSI OVERBOUGHT - SHORT SIGNAL', [
                'symbol' => $symbol,
                'current_price' => $currentPrice,
                'rsi' => $rsi,
                'threshold' => $rsiOverbought,
            ]);

            return [
                'action' => 'short',
                'quantity' => $params['trade_size'] ?? 1,
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

    /**
     * ポジションを決済すべきか判定
     *
     * @param Position $position ポジション
     * @param float $currentPrice 現在価格
     * @return array|null 決済する場合は['reason' => string]、しない場合はnull
     */
    public function shouldClosePosition(Position $position, float $currentPrice): ?array
    {
        $params = $this->getParameters();
        $rsiExitThreshold = $params['rsi_exit_threshold'] ?? 50;
        $maxHoldMinutes = $params['max_hold_minutes'] ?? 60;

        // RSIが計算されていない場合はスキップ
        if ($this->currentRSI === null) {
            return null;
        }

        // 1. RSIベースの利確判定
        if ($position->side === 'long' && $this->currentRSI > $rsiExitThreshold) {
            Log::info('RSI Exit - Long position take profit', [
                'position_id' => $position->id,
                'rsi' => $this->currentRSI,
                'threshold' => $rsiExitThreshold,
            ]);
            return ['reason' => 'rsi_take_profit', 'rsi' => $this->currentRSI];
        }

        if ($position->side === 'short' && $this->currentRSI < $rsiExitThreshold) {
            Log::info('RSI Exit - Short position take profit', [
                'position_id' => $position->id,
                'rsi' => $this->currentRSI,
                'threshold' => $rsiExitThreshold,
            ]);
            return ['reason' => 'rsi_take_profit', 'rsi' => $this->currentRSI];
        }

        // 2. タイムアウト判定
        $holdMinutes = now()->diffInMinutes($position->opened_at);
        if ($holdMinutes >= $maxHoldMinutes) {
            Log::info('RSI Exit - Timeout', [
                'position_id' => $position->id,
                'hold_minutes' => $holdMinutes,
                'max_hold_minutes' => $maxHoldMinutes,
            ]);
            return ['reason' => 'timeout', 'hold_minutes' => $holdMinutes];
        }

        return null;
    }

    /**
     * 現在のRSI値を取得
     */
    public function getCurrentRSI(): ?float
    {
        return $this->currentRSI;
    }
}
