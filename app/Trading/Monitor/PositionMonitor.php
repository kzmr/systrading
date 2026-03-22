<?php

namespace App\Trading\Monitor;

use App\Models\Position;
use App\Models\TradingLog;
use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use Illuminate\Support\Facades\Log;

use App\Mail\TradingNotification;
use Illuminate\Support\Facades\Mail;

/**
 * ポジション監視クラス
 *
 * オープンポジションのトレーリングストップ更新・STOP注文管理・緊急決済を
 * 高頻度（15〜30秒間隔）で実行する。エントリー判断は行わない。
 */
class PositionMonitor
{
    private ExchangeClient $exchangeClient;

    public function __construct(ExchangeClient $exchangeClient)
    {
        $this->exchangeClient = $exchangeClient;
    }

    /**
     * 全オープンポジションを監視
     *
     * @return array 監視結果
     */
    public function monitorAll(): array
    {
        $results = [];

        // オープンポジションをシンボルごとにグループ化
        $positions = Position::where('status', 'open')->get();

        if ($positions->isEmpty()) {
            return ['monitored' => 0, 'actions' => []];
        }

        $symbolGroups = $positions->groupBy('symbol');

        foreach ($symbolGroups as $symbol => $symbolPositions) {
            try {
                $currentPrice = $this->exchangeClient->getCurrentPrice($symbol);

                foreach ($symbolPositions as $position) {
                    $params = $this->getPositionParams($position);
                    if ($params === null) {
                        continue;
                    }

                    // 1. 決済STOP注文の約定チェック
                    $exitResult = $this->checkExitOrderExecution($position, $symbol, $currentPrice);
                    if ($exitResult !== null) {
                        $results[] = $exitResult;
                        continue; // ポジションが閉じられたので次へ
                    }

                    // 2. トレーリングストップ更新
                    $tsResult = $this->updateTrailingStop($position, $symbol, $currentPrice, $params);
                    if ($tsResult !== null) {
                        $results[] = $tsResult;
                    }

                    // 3. フォールバック決済チェック（STOP注文がないポジション）
                    $fbResult = $this->checkTrailingStopFallback($position, $symbol, $currentPrice, $params);
                    if ($fbResult !== null) {
                        $results[] = $fbResult;
                    }
                }
            } catch (\Exception $e) {
                Log::error('Position monitor error', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'action' => 'error',
                    'symbol' => $symbol,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'monitored' => $positions->count(),
            'actions' => $results,
        ];
    }

    /**
     * ポジションのTradingSettingsからパラメータを取得
     */
    private function getPositionParams(Position $position): ?array
    {
        $settings = TradingSettings::find($position->trading_settings_id);
        if (!$settings) {
            Log::warning('TradingSettings not found for position', [
                'position_id' => $position->id,
                'trading_settings_id' => $position->trading_settings_id,
            ]);
            return null;
        }
        return $settings->parameters;
    }

    /**
     * 決済STOP注文の約定チェック
     *
     * @return array|null アクションが発生した場合は結果配列、なければnull
     */
    public function checkExitOrderExecution(Position $position, string $symbol, float $currentPrice): ?array
    {
        if (!$position->exit_order_id) {
            return null;
        }

        $orderStatus = $this->exchangeClient->getOrderStatus($position->exit_order_id);

        if ($orderStatus['status'] === 'EXECUTED') {
            $this->handleExecutedExitOrder($position, $symbol);
            return [
                'action' => 'exit_order_executed',
                'symbol' => $symbol,
                'position_id' => $position->id,
                'side' => $position->side,
            ];
        }

        if (in_array($orderStatus['status'], ['CANCELED', 'EXPIRED', 'NOT_FOUND'])) {
            Log::warning('Exit order invalid, will be replaced', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'order_id' => $position->exit_order_id,
                'status' => $orderStatus['status'],
            ]);

            $position->update([
                'exit_order_id' => null,
                'exit_order_price' => null,
            ]);
            return null;
        }

        if ($orderStatus['status'] === 'WAITING') {
            $emergencyThreshold = 0.005; // 0.5%

            $shouldEmergencyExit = false;
            if ($position->side === 'long') {
                $shouldEmergencyExit = $currentPrice < $position->exit_order_price * (1 - $emergencyThreshold);
            } else {
                $shouldEmergencyExit = $currentPrice > $position->exit_order_price * (1 + $emergencyThreshold);
            }

            if ($shouldEmergencyExit) {
                $reason = $position->side === 'long' ? 'price_gap_down' : 'price_gap_up';
                $this->executeEmergencyExit($position, $symbol, $currentPrice, $reason);
                return [
                    'action' => 'emergency_exit',
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'side' => $position->side,
                    'reason' => $reason,
                ];
            }
        }

        return null;
    }

    /**
     * トレーリングストップを更新
     *
     * @return array|null トレーリングストップが更新された場合は結果配列
     */
    public function updateTrailingStop(Position $position, string $symbol, float $currentPrice, array $params): ?array
    {
        // ポジションがまだオープンか確認（前のステップで閉じられている可能性）
        $position->refresh();
        if ($position->status !== 'open') {
            return null;
        }

        $trailingOffsetPercent = (float) ($params['trailing_stop_offset_percent'] ?? 0.5);
        $trailingOffset = $trailingOffsetPercent / 100;
        $initialTrailingPercent = (float) ($params['initial_trailing_stop_percent'] ?? 0.7);

        if ($position->side === 'long') {
            return $this->updateTrailingStopLong($position, $symbol, $currentPrice, $trailingOffset, $initialTrailingPercent, $params);
        } else {
            return $this->updateTrailingStopShort($position, $symbol, $currentPrice, $trailingOffset, $initialTrailingPercent, $params);
        }
    }

    private function updateTrailingStopLong(Position $position, string $symbol, float $currentPrice, float $trailingOffset, float $initialTrailingPercent, array $params): ?array
    {
        $newTrailingStop = $currentPrice * (1 - $trailingOffset);

        // トレーリングストップが未初期化の場合
        if ($position->trailing_stop_price === null) {
            $initialStop = $position->entry_price * (1 - $initialTrailingPercent / 100);
            $position->update(['trailing_stop_price' => $initialStop]);

            $position->refresh();
            $exitPrice = $this->calculateExitPrice($position, $initialStop, $params);
            $this->placeOrUpdateExitOrder($position, $symbol, $exitPrice);

            Log::info('Monitor: Trailing stop initialized - Long', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'initial_stop' => $initialStop,
            ]);
            return ['action' => 'trailing_stop_initialized', 'symbol' => $symbol, 'position_id' => $position->id];
        }

        // より有利（高い）場合のみ更新
        if ($newTrailingStop > $position->trailing_stop_price) {
            $oldStop = $position->trailing_stop_price;
            $position->update(['trailing_stop_price' => $newTrailingStop]);

            Log::info('Monitor: Trailing stop updated - Long', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'old_stop' => $oldStop,
                'new_stop' => $newTrailingStop,
                'current_price' => $currentPrice,
            ]);

            $position->refresh();
            $exitPrice = $this->calculateExitPrice($position, $newTrailingStop, $params);
            $this->placeOrUpdateExitOrder($position, $symbol, $exitPrice);

            return ['action' => 'trailing_stop_updated', 'symbol' => $symbol, 'position_id' => $position->id, 'new_stop' => $newTrailingStop];
        }

        // STOP注文がない場合は発注
        if (!$position->exit_order_id) {
            $exitPrice = $this->calculateExitPrice($position, $position->trailing_stop_price, $params);
            $this->placeOrUpdateExitOrder($position, $symbol, $exitPrice);
            return ['action' => 'exit_order_placed', 'symbol' => $symbol, 'position_id' => $position->id];
        }

        return null;
    }

    private function updateTrailingStopShort(Position $position, string $symbol, float $currentPrice, float $trailingOffset, float $initialTrailingPercent, array $params): ?array
    {
        $newTrailingStop = $currentPrice * (1 + $trailingOffset);

        // トレーリングストップが未初期化の場合
        if ($position->trailing_stop_price === null) {
            $initialStop = $position->entry_price * (1 + $initialTrailingPercent / 100);
            $position->update(['trailing_stop_price' => $initialStop]);

            $position->refresh();
            $exitPrice = $this->calculateExitPrice($position, $initialStop, $params);
            $this->placeOrUpdateExitOrder($position, $symbol, $exitPrice);

            Log::info('Monitor: Trailing stop initialized - Short', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'initial_stop' => $initialStop,
            ]);
            return ['action' => 'trailing_stop_initialized', 'symbol' => $symbol, 'position_id' => $position->id];
        }

        // より有利（低い）場合のみ更新
        if ($newTrailingStop < $position->trailing_stop_price) {
            $oldStop = $position->trailing_stop_price;
            $position->update(['trailing_stop_price' => $newTrailingStop]);

            Log::info('Monitor: Trailing stop updated - Short', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'old_stop' => $oldStop,
                'new_stop' => $newTrailingStop,
                'current_price' => $currentPrice,
            ]);

            $position->refresh();
            $exitPrice = $this->calculateExitPrice($position, $newTrailingStop, $params);
            $this->placeOrUpdateExitOrder($position, $symbol, $exitPrice);

            return ['action' => 'trailing_stop_updated', 'symbol' => $symbol, 'position_id' => $position->id, 'new_stop' => $newTrailingStop];
        }

        // STOP注文がない場合は発注
        if (!$position->exit_order_id) {
            $exitPrice = $this->calculateExitPrice($position, $position->trailing_stop_price, $params);
            $this->placeOrUpdateExitOrder($position, $symbol, $exitPrice);
            return ['action' => 'exit_order_placed', 'symbol' => $symbol, 'position_id' => $position->id];
        }

        return null;
    }

    /**
     * トレーリングストップによる決済チェック（フォールバック）
     * STOP注文がないポジションに対してのみ成行で決済
     *
     * @return array|null 決済が実行された場合は結果配列
     */
    public function checkTrailingStopFallback(Position $position, string $symbol, float $currentPrice, array $params): ?array
    {
        // STOP注文がある場合はスキップ（取引所側で処理される）
        if ($position->exit_order_id) {
            return null;
        }

        // ポジションがまだオープンか確認
        $position->refresh();
        if ($position->status !== 'open') {
            return null;
        }

        if ($position->trailing_stop_price === null) {
            return null;
        }

        $exitPrice = $this->calculateExitPrice($position, $position->trailing_stop_price, $params);

        if ($position->side === 'long' && $currentPrice <= $exitPrice) {
            return $this->executeFallbackExit($position, $symbol, $currentPrice, 'long');
        }

        if ($position->side === 'short' && $currentPrice >= $exitPrice) {
            return $this->executeFallbackExit($position, $symbol, $currentPrice, 'short');
        }

        return null;
    }

    /**
     * フォールバック成行決済を実行
     */
    private function executeFallbackExit(Position $position, string $symbol, float $currentPrice, string $side): ?array
    {
        if ($side === 'long') {
            $result = $this->exchangeClient->sell($symbol, $position->quantity, null);
        } else {
            $result = $this->exchangeClient->buy($symbol, $position->quantity, null);
        }

        if (!$result['success']) {
            return null;
        }

        if ($side === 'long') {
            $profitLoss = ($result['price'] - $position->entry_price) * $position->quantity;
        } else {
            $profitLoss = ($position->entry_price - $result['price']) * $position->quantity;
        }

        $position->update([
            'exit_price' => $result['price'],
            'exit_fee' => $result['fee'] ?? 0,
            'status' => 'closed',
            'closed_at' => now(),
            'profit_loss' => $profitLoss,
        ]);

        $action = $side === 'long' ? 'fallback_trailing_stop_sell' : 'fallback_trailing_stop_buy';
        $sideLabel = $side === 'long' ? 'ロング' : 'ショート';

        Log::info("Monitor: Fallback trailing stop triggered - {$side} position closed", [
            'symbol' => $symbol,
            'position_id' => $position->id,
            'entry_price' => $position->entry_price,
            'exit_price' => $result['price'],
            'trailing_stop' => $position->trailing_stop_price,
            'profit_loss' => $profitLoss,
        ]);

        TradingLog::create([
            'symbol' => $symbol,
            'action' => $action,
            'quantity' => $position->quantity,
            'price' => $result['price'],
            'message' => sprintf(
                'モニター フォールバック決済 - %s (エントリー: %.2f円 → 決済: %.2f円, 損益: %.4f円)',
                $sideLabel,
                $position->entry_price,
                $result['price'],
                $profitLoss
            ),
            'executed_at' => now(),
        ]);

        $this->sendNotification(
            'exit',
            $symbol,
            $side,
            $result['price'],
            $position->quantity,
            $profitLoss,
            'モニター フォールバック成行決済（Taker手数料）',
            $this->getPositionStrategyName($position)
        );

        return [
            'action' => $action,
            'symbol' => $symbol,
            'position_id' => $position->id,
            'profit_loss' => $profitLoss,
        ];
    }

    /**
     * ポジションの決済価格を計算（トレーリングストップと損切りの保護的な方）
     */
    private function calculateExitPrice(Position $position, float $trailingStopPrice, array $params): float
    {
        $stopLossPercent = (float) ($params['stop_loss_percent'] ?? 1.0);

        if ($position->side === 'long') {
            $stopLossPrice = $position->entry_price * (1 - $stopLossPercent / 100);
            return max($trailingStopPrice, $stopLossPrice);
        } else {
            $stopLossPrice = $position->entry_price * (1 + $stopLossPercent / 100);
            return min($trailingStopPrice, $stopLossPrice);
        }
    }

    /**
     * 決済STOP注文を発注または更新
     */
    private function placeOrUpdateExitOrder(Position $position, string $symbol, float $exitPrice): void
    {
        // 既存の注文があり、価格が同じなら何もしない
        if ($position->exit_order_id && abs($position->exit_order_price - $exitPrice) < 0.01) {
            return;
        }

        // 既存の注文があればキャンセル
        if ($position->exit_order_id) {
            $cancelResult = $this->exchangeClient->cancelOrder($position->exit_order_id);

            if ($cancelResult['success']) {
                Log::info('Monitor: Exit order canceled for update', [
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'old_order_id' => $position->exit_order_id,
                    'old_price' => $position->exit_order_price,
                ]);
            } else {
                $orderStatus = $this->exchangeClient->getOrderStatus($position->exit_order_id);

                if ($orderStatus['status'] === 'EXECUTED') {
                    $this->handleExecutedExitOrder($position, $symbol);
                    return;
                }

                Log::warning('Monitor: Failed to cancel exit order', [
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'order_id' => $position->exit_order_id,
                    'status' => $orderStatus['status'],
                ]);
            }
        }

        // 新しいSTOP注文を発注
        if ($position->side === 'long') {
            $orderResult = $this->exchangeClient->stopSell($symbol, $position->quantity, $exitPrice);
        } else {
            $orderResult = $this->exchangeClient->stopBuy($symbol, $position->quantity, $exitPrice);
        }

        if ($orderResult['success']) {
            $position->update([
                'exit_order_id' => $orderResult['order_id'],
                'exit_order_price' => $exitPrice,
            ]);

            Log::info('Monitor: Exit stop order placed', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'side' => $position->side,
                'order_id' => $orderResult['order_id'],
                'trigger_price' => $exitPrice,
            ]);
        } else {
            Log::error('Monitor: Failed to place exit stop order', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'trigger_price' => $exitPrice,
                'error' => $orderResult['message'] ?? 'Unknown error',
            ]);
        }
    }

    /**
     * 約定済み決済注文の処理
     */
    private function handleExecutedExitOrder(Position $position, string $symbol): void
    {
        $executions = $this->exchangeClient->getExecutionsByOrderId($position->exit_order_id);

        $exitPrice = $position->exit_order_price;
        $fee = 0;

        if (!empty($executions)) {
            $totalValue = 0;
            $totalSize = 0;
            foreach ($executions as $execution) {
                $price = (float) $execution['price'];
                $size = (float) $execution['size'];
                $fee += (float) ($execution['fee'] ?? 0);
                $totalValue += $price * $size;
                $totalSize += $size;
            }
            if ($totalSize > 0) {
                $exitPrice = $totalValue / $totalSize;
            }
        }

        if ($position->side === 'long') {
            $profitLoss = ($exitPrice - $position->entry_price) * $position->quantity;
        } else {
            $profitLoss = ($position->entry_price - $exitPrice) * $position->quantity;
        }

        $position->update([
            'exit_price' => $exitPrice,
            'exit_fee' => $fee,
            'status' => 'closed',
            'closed_at' => now(),
            'profit_loss' => $profitLoss,
            'exit_order_id' => null,
            'exit_order_price' => null,
        ]);

        Log::info('Monitor: Exit order executed', [
            'symbol' => $symbol,
            'position_id' => $position->id,
            'side' => $position->side,
            'entry_price' => $position->entry_price,
            'exit_price' => $exitPrice,
            'profit_loss' => $profitLoss,
            'fee' => $fee,
        ]);

        TradingLog::create([
            'symbol' => $symbol,
            'action' => 'limit_exit_' . ($position->side === 'long' ? 'sell' : 'buy'),
            'quantity' => $position->quantity,
            'price' => $exitPrice,
            'message' => sprintf(
                'モニター STOP決済約定 - %s (エントリー: %.2f円 → 決済: %.2f円, 損益: %.4f円)',
                $position->side === 'long' ? 'ロング' : 'ショート',
                $position->entry_price,
                $exitPrice,
                $profitLoss
            ),
            'executed_at' => now(),
        ]);

        $this->sendNotification(
            'exit',
            $symbol,
            $position->side,
            $exitPrice,
            $position->quantity,
            $profitLoss,
            'モニター STOP決済約定',
            $this->getPositionStrategyName($position)
        );
    }

    /**
     * 緊急成行決済
     */
    private function executeEmergencyExit(Position $position, string $symbol, float $currentPrice, string $reason): void
    {
        $this->exchangeClient->cancelOrder($position->exit_order_id);

        if ($position->side === 'long') {
            $result = $this->exchangeClient->sell($symbol, $position->quantity, null);
        } else {
            $result = $this->exchangeClient->buy($symbol, $position->quantity, null);
        }

        if ($result['success']) {
            if ($position->side === 'long') {
                $profitLoss = ($result['price'] - $position->entry_price) * $position->quantity;
            } else {
                $profitLoss = ($position->entry_price - $result['price']) * $position->quantity;
            }

            $position->update([
                'exit_price' => $result['price'],
                'exit_fee' => $result['fee'] ?? 0,
                'status' => 'closed',
                'closed_at' => now(),
                'profit_loss' => $profitLoss,
                'exit_order_id' => null,
                'exit_order_price' => null,
            ]);

            Log::warning('Monitor: Emergency exit executed', [
                'symbol' => $symbol,
                'position_id' => $position->id,
                'reason' => $reason,
                'exit_order_price' => $position->exit_order_price,
                'actual_exit_price' => $result['price'],
                'profit_loss' => $profitLoss,
            ]);

            TradingLog::create([
                'symbol' => $symbol,
                'action' => 'emergency_exit_' . ($position->side === 'long' ? 'sell' : 'buy'),
                'quantity' => $position->quantity,
                'price' => $result['price'],
                'message' => sprintf(
                    'モニター 緊急成行決済 - %s (理由: %s, STOP価格: %.2f円 → 実際: %.2f円, 損益: %.4f円)',
                    $position->side === 'long' ? 'ロング' : 'ショート',
                    $reason,
                    $position->exit_order_price,
                    $result['price'],
                    $profitLoss
                ),
                'executed_at' => now(),
            ]);

            $this->sendNotification(
                'exit',
                $symbol,
                $position->side,
                $result['price'],
                $position->quantity,
                $profitLoss,
                'モニター 緊急成行決済（価格ギャップ検知）',
                $this->getPositionStrategyName($position)
            );
        }
    }

    /**
     * ポジションの戦略名を取得
     */
    private function getPositionStrategyName(Position $position): string
    {
        if ($position->trading_settings_id) {
            $settings = TradingSettings::find($position->trading_settings_id);
            if ($settings) {
                return $settings->name;
            }
        }
        return 'Unknown Strategy';
    }

    /**
     * メール通知を送信
     */
    private function sendNotification(
        string $action,
        string $symbol,
        string $side,
        float $price,
        float $quantity,
        ?float $profitLoss = null,
        ?string $reason = null,
        ?string $strategyName = null
    ): void {
        if (!env('TRADING_NOTIFICATION_ENABLED', false)) {
            return;
        }

        $email = env('TRADING_NOTIFICATION_EMAIL');
        if (!$email) {
            return;
        }

        try {
            $profitLossPercent = null;
            if ($profitLoss !== null && $price > 0 && $quantity > 0) {
                $profitLossPercent = ($profitLoss / ($price * $quantity)) * 100;
            }

            Mail::to($email)->send(new TradingNotification(
                action: $action,
                symbol: $symbol,
                side: $side,
                price: $price,
                quantity: $quantity,
                profitLoss: $profitLoss,
                profitLossPercent: $profitLossPercent,
                reason: $reason,
                strategyName: $strategyName,
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to send trading notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
