<?php

namespace App\Trading\Executor;

use App\Models\Position;
use App\Models\PriceHistory;
use App\Models\TradingLog;
use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use App\Trading\Strategy\TradingStrategy;
use Illuminate\Support\Facades\Log;

use App\Mail\TradingNotification;
use Illuminate\Support\Facades\Mail;

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
     * 戦略のパラメータを取得（DBから読み込み）
     */
    private function getParam(string $key, mixed $default = null): mixed
    {
        $params = $this->strategy->getParameters();
        return $params[$key] ?? $default;
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

            // 現在価格を記録（バックテスト用）
            $currentPrice = end($marketData['prices']);
            PriceHistory::create([
                'symbol' => $symbol,
                'price' => $currentPrice,
                'recorded_at' => now(),
            ]);

            // 2. 戦略ベースの決済チェック（RSI逆張り等）
            $this->checkStrategyBasedExit($symbol, $currentPrice, $marketData);

            // 3. トレーリングストップの更新と確認
            $this->updateTrailingStop($symbol, $currentPrice);
            $this->checkTrailingStop($symbol, $currentPrice);

            // 4. 固定損切りチェック（1%逆行で自動決済、最終防衛ライン）
            $this->checkStopLoss($symbol, $currentPrice);

            // 5. ストラテジーで分析
            $signal = $this->strategy->analyze($marketData);

            // 成行注文の場合は現在価格をセット（スプレッドチェック用）
            if ($signal['price'] === null) {
                $signal['price'] = $currentPrice;
            }

            // 6. シグナルに基づいて注文実行
            $result = $this->processSignal($symbol, $signal);

            // 7. ログを記録
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
            // ショートポジションが存在する場合は全てクローズ
            $shortPositions = Position::where('symbol', $symbol)
                ->where('side', 'short')
                ->where('status', 'open')
                ->orderBy('opened_at', 'asc')
                ->get();

            if ($shortPositions->count() > 0) {
                // 全ショートポジションを一斉決済
                $totalProfitLoss = 0;
                foreach ($shortPositions as $shortPosition) {
                    $closeResult = $this->exchangeClient->buy($symbol, $shortPosition->quantity, null); // 成行注文

                    if ($closeResult['success']) {
                        // ショートポジションの損益は逆転: (entry_price - exit_price) * quantity
                        $profitLoss = ($shortPosition->entry_price - $closeResult['price']) * $shortPosition->quantity;
                        $totalProfitLoss += $profitLoss;

                        $shortPosition->update([
                            'exit_price' => $closeResult['price'],
                            'status' => 'closed',
                            'closed_at' => now(),
                            'profit_loss' => $profitLoss,
                        ]);

                        // エグジット通知を送信
                        $this->sendNotification(
                            'exit',
                            $symbol,
                            'short',
                            $closeResult['price'],
                            $shortPosition->quantity,
                            $profitLoss,
                            '逆方向ブレイクアウト - ロングエントリーのため決済'
                        );
                    }
                }

                Log::info('All short positions closed, proceeding to long entry', [
                    'symbol' => $symbol,
                    'count' => $shortPositions->count(),
                    'total_profit_loss' => $totalProfitLoss,
                ]);

                // 一斉決済後、そのまま新規ロングエントリー処理へ進む
            }

            // ロングポジション数をカウント
            $longCount = Position::where('symbol', $symbol)
                ->where('side', 'long')
                ->where('status', 'open')
                ->count();

            // 上限チェック（DBから取得、デフォルト3）
            $maxPositions = (int) $this->getParam('max_positions', 3);
            if ($longCount >= $maxPositions) {
                Log::info('Long position limit reached', [
                    'symbol' => $symbol,
                    'current_count' => $longCount,
                    'max_positions' => $maxPositions,
                ]);

                return [
                    'success' => false,
                    'action' => 'buy_rejected',
                    'message' => "ロングポジション上限到達 ({$longCount}/{$maxPositions}) - エントリー見送り",
                ];
            }

            // 新規エントリー時はスプレッドをチェック（割合ベース、DBから取得）
            $maxSpreadPercent = (float) $this->getParam('max_spread', 0.1); // デフォルト0.1%
            $maxSpreadPercentage = $maxSpreadPercent / 100; // パーセントを小数に変換
            $currentSpread = $this->exchangeClient->getSpread($symbol);
            $maxSpreadValue = $signal['price'] * $maxSpreadPercentage;

            if ($currentSpread > $maxSpreadValue) {
                $spreadPercentage = ($currentSpread / $signal['price']) * 100;

                Log::warning('Spread too wide for entry', [
                    'symbol' => $symbol,
                    'current_price' => $signal['price'],
                    'current_spread' => $currentSpread,
                    'spread_percentage' => $spreadPercentage,
                    'max_spread_percentage' => $maxSpreadPercent,
                ]);

                return [
                    'success' => false,
                    'action' => 'buy_rejected',
                    'message' => sprintf(
                        'スプレッド超過 (%.4f円 = %.2f%% > %.2f%%) - エントリー見送り',
                        $currentSpread,
                        $spreadPercentage,
                        $maxSpreadPercent
                    ),
                ];
            }

            $result = $this->exchangeClient->buy($symbol, $signal['quantity'], null); // 成行注文

            if ($result['success']) {
                // ポジションを記録（初期トレーリングストップ: DBから取得）
                $initialTrailingPercent = (float) $this->getParam('initial_trailing_stop_percent', 0.7);
                Position::create([
                    'symbol' => $symbol,
                    'trading_settings_id' => $this->strategy->getSettingsId(),
                    'side' => 'long',
                    'quantity' => $signal['quantity'],
                    'entry_price' => $result['price'],
                    'trailing_stop_price' => $result['price'] * (1 - $initialTrailingPercent / 100),
                    'status' => 'open',
                    'opened_at' => now(),
                ]);

                // エントリー通知を送信
                $this->sendNotification(
                    'entry',
                    $symbol,
                    'long',
                    $result['price'],
                    $signal['quantity']
                );

                Log::info('Long position added', [
                    'symbol' => $symbol,
                    'current_count' => $longCount + 1,
                    'entry_price' => $result['price'],
                ]);
            }

            return $result;
        }

        if ($signal['action'] === 'sell') {
            $result = $this->exchangeClient->sell($symbol, $signal['quantity'], null); // 成行注文

            if ($result['success']) {
                // ロングポジションをクローズ
                $position = Position::where('symbol', $symbol)
                    ->where('side', 'long')
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

        if ($signal['action'] === 'short') {
            // ロングポジションが存在する場合は全てクローズ
            $longPositions = Position::where('symbol', $symbol)
                ->where('side', 'long')
                ->where('status', 'open')
                ->orderBy('opened_at', 'asc')
                ->get();

            if ($longPositions->count() > 0) {
                // 全ロングポジションを一斉決済
                $totalProfitLoss = 0;
                foreach ($longPositions as $longPosition) {
                    $closeResult = $this->exchangeClient->sell($symbol, $longPosition->quantity, null); // 成行注文

                    if ($closeResult['success']) {
                        // ロングポジションの損益計算
                        $profitLoss = ($closeResult['price'] - $longPosition->entry_price) * $longPosition->quantity;
                        $totalProfitLoss += $profitLoss;

                        $longPosition->update([
                            'exit_price' => $closeResult['price'],
                            'status' => 'closed',
                            'closed_at' => now(),
                            'profit_loss' => $profitLoss,
                        ]);

                        // エグジット通知を送信
                        $this->sendNotification(
                            'exit',
                            $symbol,
                            'long',
                            $closeResult['price'],
                            $longPosition->quantity,
                            $profitLoss,
                            '逆方向ブレイクダウン - ショートエントリーのため決済'
                        );
                    }
                }

                Log::info('All long positions closed, proceeding to short entry', [
                    'symbol' => $symbol,
                    'count' => $longPositions->count(),
                    'total_profit_loss' => $totalProfitLoss,
                ]);

                // 一斉決済後、そのまま新規ショートエントリー処理へ進む
            }

            // ショートポジション数をカウント
            $shortCount = Position::where('symbol', $symbol)
                ->where('side', 'short')
                ->where('status', 'open')
                ->count();

            // 上限チェック（DBから取得、デフォルト3）
            $maxPositions = (int) $this->getParam('max_positions', 3);
            if ($shortCount >= $maxPositions) {
                Log::info('Short position limit reached', [
                    'symbol' => $symbol,
                    'current_count' => $shortCount,
                    'max_positions' => $maxPositions,
                ]);

                return [
                    'success' => false,
                    'action' => 'short_rejected',
                    'message' => "ショートポジション上限到達 ({$shortCount}/{$maxPositions}) - エントリー見送り",
                ];
            }

            // 新規ショートエントリー時はスプレッドをチェック（割合ベース、DBから取得）
            $maxSpreadPercent = (float) $this->getParam('max_spread', 0.1); // デフォルト0.1%
            $maxSpreadPercentage = $maxSpreadPercent / 100; // パーセントを小数に変換
            $currentSpread = $this->exchangeClient->getSpread($symbol);
            $maxSpreadValue = $signal['price'] * $maxSpreadPercentage;

            if ($currentSpread > $maxSpreadValue) {
                $spreadPercentage = ($currentSpread / $signal['price']) * 100;

                Log::warning('Spread too wide for short entry', [
                    'symbol' => $symbol,
                    'current_price' => $signal['price'],
                    'current_spread' => $currentSpread,
                    'spread_percentage' => $spreadPercentage,
                    'max_spread_percentage' => $maxSpreadPercent,
                ]);

                return [
                    'success' => false,
                    'action' => 'short_rejected',
                    'message' => sprintf(
                        'スプレッド超過 (%.4f円 = %.2f%% > %.2f%%) - ショートエントリー見送り',
                        $currentSpread,
                        $spreadPercentage,
                        $maxSpreadPercent
                    ),
                ];
            }

            // ショートポジションを開く（売りから入る）
            $result = $this->exchangeClient->sell($symbol, $signal['quantity'], null); // 成行注文

            if ($result['success']) {
                // ショートポジションを記録（初期トレーリングストップ: DBから取得）
                $initialTrailingPercent = (float) $this->getParam('initial_trailing_stop_percent', 0.7);
                Position::create([
                    'symbol' => $symbol,
                    'trading_settings_id' => $this->strategy->getSettingsId(),
                    'side' => 'short',
                    'quantity' => $signal['quantity'],
                    'entry_price' => $result['price'],
                    'trailing_stop_price' => $result['price'] * (1 + $initialTrailingPercent / 100),
                    'status' => 'open',
                    'opened_at' => now(),
                ]);

                // エントリー通知を送信
                $this->sendNotification(
                    'entry',
                    $symbol,
                    'short',
                    $result['price'],
                    $signal['quantity']
                );

                Log::info('Short position added', [
                    'symbol' => $symbol,
                    'current_count' => $shortCount + 1,
                    'entry_price' => $result['price'],
                ]);
            }

            return $result;
        }

        return [
            'success' => false,
            'message' => '不明なアクション: ' . $signal['action'],
        ];
    }

    /**
     * 戦略ベースの決済チェック（RSI逆張り等）
     * 戦略がshouldClosePositionメソッドを持つ場合のみ実行
     */
    private function checkStrategyBasedExit(string $symbol, float $currentPrice, array $marketData): void
    {
        // 戦略がshouldClosePositionメソッドを持つか確認
        if (!method_exists($this->strategy, 'shouldClosePosition')) {
            return;
        }

        // 自分の戦略で作成したポジションのみを対象にする
        $positions = Position::where('symbol', $symbol)
            ->where('status', 'open')
            ->where('trading_settings_id', $this->strategy->getSettingsId())
            ->get();

        foreach ($positions as $position) {
            $exitResult = $this->strategy->shouldClosePosition($position, $currentPrice, $marketData);

            if ($exitResult === null) {
                continue;
            }

            $reason = $exitResult['reason'] ?? 'strategy_exit';

            // ロングポジションの決済
            if ($position->side === 'long') {
                $sellResult = $this->exchangeClient->sell($symbol, $position->quantity, null);

                if ($sellResult['success']) {
                    $profitLoss = ($sellResult['price'] - $position->entry_price) * $position->quantity;

                    $position->update([
                        'exit_price' => $sellResult['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => $profitLoss,
                    ]);

                    $reasonMessage = $this->getExitReasonMessage($reason, $exitResult);

                    Log::info('Strategy-based exit - Long position closed', [
                        'symbol' => $symbol,
                        'position_id' => $position->id,
                        'reason' => $reason,
                        'entry_price' => $position->entry_price,
                        'exit_price' => $sellResult['price'],
                        'profit_loss' => $profitLoss,
                        'details' => $exitResult,
                    ]);

                    TradingLog::create([
                        'symbol' => $symbol,
                        'action' => 'strategy_exit_sell',
                        'quantity' => $position->quantity,
                        'price' => $sellResult['price'],
                        'message' => sprintf(
                            '%s - ロング決済 (エントリー: %.2f円 → 決済: %.2f円, 損益: %.4f円)',
                            $reasonMessage,
                            $position->entry_price,
                            $sellResult['price'],
                            $profitLoss
                        ),
                        'executed_at' => now(),
                    ]);

                    $this->sendNotification(
                        'exit',
                        $symbol,
                        'long',
                        $sellResult['price'],
                        $position->quantity,
                        $profitLoss,
                        $reasonMessage
                    );
                }
            }

            // ショートポジションの決済
            if ($position->side === 'short') {
                $buyResult = $this->exchangeClient->buy($symbol, $position->quantity, null);

                if ($buyResult['success']) {
                    $profitLoss = ($position->entry_price - $buyResult['price']) * $position->quantity;

                    $position->update([
                        'exit_price' => $buyResult['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => $profitLoss,
                    ]);

                    $reasonMessage = $this->getExitReasonMessage($reason, $exitResult);

                    Log::info('Strategy-based exit - Short position closed', [
                        'symbol' => $symbol,
                        'position_id' => $position->id,
                        'reason' => $reason,
                        'entry_price' => $position->entry_price,
                        'exit_price' => $buyResult['price'],
                        'profit_loss' => $profitLoss,
                        'details' => $exitResult,
                    ]);

                    TradingLog::create([
                        'symbol' => $symbol,
                        'action' => 'strategy_exit_buy',
                        'quantity' => $position->quantity,
                        'price' => $buyResult['price'],
                        'message' => sprintf(
                            '%s - ショート決済 (エントリー: %.2f円 → 決済: %.2f円, 損益: %.4f円)',
                            $reasonMessage,
                            $position->entry_price,
                            $buyResult['price'],
                            $profitLoss
                        ),
                        'executed_at' => now(),
                    ]);

                    $this->sendNotification(
                        'exit',
                        $symbol,
                        'short',
                        $buyResult['price'],
                        $position->quantity,
                        $profitLoss,
                        $reasonMessage
                    );
                }
            }
        }
    }

    /**
     * 決済理由のメッセージを取得
     */
    private function getExitReasonMessage(string $reason, array $details): string
    {
        return match ($reason) {
            'rsi_take_profit' => sprintf('RSI利確 (RSI=%.2f)', $details['rsi'] ?? 0),
            'timeout' => sprintf('タイムアウト決済 (%d分経過)', $details['hold_minutes'] ?? 0),
            default => '戦略ベース決済',
        };
    }

    /**
     * 損切りチェック（エントリーから指定%逆行で決済）
     */
    private function checkStopLoss(string $symbol, float $currentPrice): void
    {
        $stopLossPercent = (float) $this->getParam('stop_loss_percent', 1.0);
        $stopLossPercentage = $stopLossPercent / 100; // パーセントを小数に変換

        // ロングポジションの損切りチェック
        $longPositions = Position::where('symbol', $symbol)
            ->where('side', 'long')
            ->where('status', 'open')
            ->get();

        foreach ($longPositions as $position) {
            $stopLossPrice = $position->entry_price * (1 - $stopLossPercentage);

            if ($currentPrice <= $stopLossPrice) {
                // 損切り実行
                $sellResult = $this->exchangeClient->sell($symbol, $position->quantity, null); // 成行注文

                if ($sellResult['success']) {
                    $profitLoss = ($sellResult['price'] - $position->entry_price) * $position->quantity;

                    $position->update([
                        'exit_price' => $sellResult['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => $profitLoss,
                    ]);

                    Log::warning('Stop loss triggered - Long position closed', [
                        'symbol' => $symbol,
                        'position_id' => $position->id,
                        'entry_price' => $position->entry_price,
                        'exit_price' => $sellResult['price'],
                        'stop_loss_price' => $stopLossPrice,
                        'profit_loss' => $profitLoss,
                        'percentage' => ($profitLoss / ($position->entry_price * $position->quantity)) * 100,
                    ]);

                    TradingLog::create([
                        'symbol' => $symbol,
                        'action' => 'stop_loss_sell',
                        'quantity' => $position->quantity,
                        'price' => $sellResult['price'],
                        'message' => sprintf(
                            '損切り実行 - ロング決済 (エントリー: %.2f円 → 損切り: %.2f円, 損失: %.4f円)',
                            $position->entry_price,
                            $sellResult['price'],
                            $profitLoss
                        ),
                        'executed_at' => now(),
                    ]);

                    // エグジット通知を送信（元の戦略名を使用）
                    $this->sendNotification(
                        'exit',
                        $symbol,
                        'long',
                        $sellResult['price'],
                        $position->quantity,
                        $profitLoss,
                        '損切り実行 - 1%ストップロス到達',
                        $this->getPositionStrategyName($position)
                    );
                }
            }
        }

        // ショートポジションの損切りチェック
        $shortPositions = Position::where('symbol', $symbol)
            ->where('side', 'short')
            ->where('status', 'open')
            ->get();

        foreach ($shortPositions as $position) {
            $stopLossPrice = $position->entry_price * (1 + $stopLossPercentage);

            if ($currentPrice >= $stopLossPrice) {
                // 損切り実行（ショートは買い戻し）
                $buyResult = $this->exchangeClient->buy($symbol, $position->quantity, null); // 成行注文

                if ($buyResult['success']) {
                    // ショートポジションの損益: (entry_price - exit_price) * quantity
                    $profitLoss = ($position->entry_price - $buyResult['price']) * $position->quantity;

                    $position->update([
                        'exit_price' => $buyResult['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => $profitLoss,
                    ]);

                    Log::warning('Stop loss triggered - Short position closed', [
                        'symbol' => $symbol,
                        'position_id' => $position->id,
                        'entry_price' => $position->entry_price,
                        'exit_price' => $buyResult['price'],
                        'stop_loss_price' => $stopLossPrice,
                        'profit_loss' => $profitLoss,
                        'percentage' => ($profitLoss / ($position->entry_price * $position->quantity)) * 100,
                    ]);

                    TradingLog::create([
                        'symbol' => $symbol,
                        'action' => 'stop_loss_buy',
                        'quantity' => $position->quantity,
                        'price' => $buyResult['price'],
                        'message' => sprintf(
                            '損切り実行 - ショート決済 (エントリー: %.2f円 → 損切り: %.2f円, 損失: %.4f円)',
                            $position->entry_price,
                            $buyResult['price'],
                            $profitLoss
                        ),
                        'executed_at' => now(),
                    ]);

                    // エグジット通知を送信（元の戦略名を使用）
                    $this->sendNotification(
                        'exit',
                        $symbol,
                        'short',
                        $buyResult['price'],
                        $position->quantity,
                        $profitLoss,
                        '損切り実行 - 1%ストップロス到達',
                        $this->getPositionStrategyName($position)
                    );
                }
            }
        }
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

    /**
     * ポジションの元の戦略名を取得
     */
    private function getPositionStrategyName(Position $position): string
    {
        if ($position->trading_settings_id) {
            $settings = TradingSettings::find($position->trading_settings_id);
            if ($settings) {
                return $settings->name;
            }
        }
        return $this->strategy->getName();
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
            if ($profitLoss !== null && $quantity > 0 && $price > 0) {
                $entryValue = $price * $quantity;
                if ($entryValue > 0) {
                    $profitLossPercent = ($profitLoss / $entryValue) * 100;
                }
            }

            // 戦略名を取得（指定がなければ現在の戦略名を使用）
            $strategyName = $strategyName ?? $this->strategy->getName();

            Mail::to($email)->send(new TradingNotification(
                action: $action,
                side: $side,
                symbol: $symbol,
                price: $price,
                quantity: $quantity,
                profitLoss: $profitLoss,
                profitLossPercent: $profitLossPercent,
                reason: $reason,
                strategyName: $strategyName
            ));

            Log::info('Trading notification sent', [
                'action' => $action,
                'symbol' => $symbol,
                'side' => $side,
                'email' => $email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send trading notification', [
                'error' => $e->getMessage(),
                'action' => $action,
                'symbol' => $symbol,
            ]);
        }
    }

    /**
     * トレーリングストップを更新（現在価格ベース）
     */
    private function updateTrailingStop(string $symbol, float $currentPrice): void
    {
        $trailingOffsetPercent = (float) $this->getParam('trailing_stop_offset_percent', 0.5);
        $trailingOffset = $trailingOffsetPercent / 100; // パーセントを小数に変換

        // ロングポジションのトレーリングストップ更新
        $longPositions = Position::where('symbol', $symbol)
            ->where('side', 'long')
            ->where('status', 'open')
            ->get();

        foreach ($longPositions as $position) {
            // トレーリングストップ = 現在価格 - 0.5%（利益方向のみ追跡）
            $newTrailingStop = $currentPrice * (1 - $trailingOffset);

            // 既存ポジション（trailing_stop_priceがnull）の場合は初期値を設定
            if ($position->trailing_stop_price === null) {
                $initialTrailingPercent = (float) $this->getParam('initial_trailing_stop_percent', 0.7);
                $initialStop = $position->entry_price * (1 - $initialTrailingPercent / 100);

                $position->update([
                    'trailing_stop_price' => $initialStop,
                ]);

                Log::info('Trailing stop initialized - Long', [
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'entry_price' => $position->entry_price,
                    'initial_stop' => $initialStop,
                ]);
                continue;
            }

            // 現在のトレーリングストップより高い（有利）場合のみ更新
            if ($newTrailingStop > $position->trailing_stop_price) {
                $position->update([
                    'trailing_stop_price' => $newTrailingStop,
                ]);

                Log::info('Trailing stop updated - Long', [
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'old_stop' => $position->trailing_stop_price,
                    'new_stop' => $newTrailingStop,
                    'current_price' => $currentPrice,
                ]);
            }
        }

        // ショートポジションのトレーリングストップ更新
        $shortPositions = Position::where('symbol', $symbol)
            ->where('side', 'short')
            ->where('status', 'open')
            ->get();

        foreach ($shortPositions as $position) {
            // トレーリングストップ = 現在価格 + 0.5%（利益方向のみ追跡）
            $newTrailingStop = $currentPrice * (1 + $trailingOffset);

            // 既存ポジション（trailing_stop_priceがnull）の場合は初期値を設定
            if ($position->trailing_stop_price === null) {
                $initialTrailingPercent = (float) $this->getParam('initial_trailing_stop_percent', 0.7);
                $initialStop = $position->entry_price * (1 + $initialTrailingPercent / 100);

                $position->update([
                    'trailing_stop_price' => $initialStop,
                ]);

                Log::info('Trailing stop initialized - Short', [
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'entry_price' => $position->entry_price,
                    'initial_stop' => $initialStop,
                ]);
                continue;
            }

            // 現在のトレーリングストップより低い（有利）場合のみ更新
            if ($newTrailingStop < $position->trailing_stop_price) {
                $position->update([
                    'trailing_stop_price' => $newTrailingStop,
                ]);

                Log::info('Trailing stop updated - Short', [
                    'symbol' => $symbol,
                    'position_id' => $position->id,
                    'old_stop' => $position->trailing_stop_price,
                    'new_stop' => $newTrailingStop,
                    'current_price' => $currentPrice,
                ]);
            }
        }
    }

    /**
     * トレーリングストップによる決済チェック
     */
    private function checkTrailingStop(string $symbol, float $currentPrice): void
    {
        // ロングポジションのトレーリングストップチェック
        $longPositions = Position::where('symbol', $symbol)
            ->where('side', 'long')
            ->where('status', 'open')
            ->get();

        foreach ($longPositions as $position) {
            if ($currentPrice <= $position->trailing_stop_price) {
                // トレーリングストップで決済
                $sellResult = $this->exchangeClient->sell($symbol, $position->quantity, null); // 成行注文

                if ($sellResult['success']) {
                    $profitLoss = ($sellResult['price'] - $position->entry_price) * $position->quantity;

                    $position->update([
                        'exit_price' => $sellResult['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => $profitLoss,
                    ]);

                    Log::info('Trailing stop triggered - Long position closed', [
                        'symbol' => $symbol,
                        'position_id' => $position->id,
                        'entry_price' => $position->entry_price,
                        'exit_price' => $sellResult['price'],
                        'trailing_stop' => $position->trailing_stop_price,
                        'profit_loss' => $profitLoss,
                        'percentage' => ($profitLoss / ($position->entry_price * $position->quantity)) * 100,
                    ]);

                    TradingLog::create([
                        'symbol' => $symbol,
                        'action' => 'trailing_stop_sell',
                        'quantity' => $position->quantity,
                        'price' => $sellResult['price'],
                        'message' => sprintf(
                            'トレーリングストップ決済 - ロング (エントリー: %.2f円 → 決済: %.2f円, 損益: %.4f円)',
                            $position->entry_price,
                            $sellResult['price'],
                            $profitLoss
                        ),
                        'executed_at' => now(),
                    ]);

                    // エグジット通知を送信（元の戦略名を使用）
                    $this->sendNotification(
                        'exit',
                        $symbol,
                        'long',
                        $sellResult['price'],
                        $position->quantity,
                        $profitLoss,
                        'トレーリングストップ到達',
                        $this->getPositionStrategyName($position)
                    );
                }
            }
        }

        // ショートポジションのトレーリングストップチェック
        $shortPositions = Position::where('symbol', $symbol)
            ->where('side', 'short')
            ->where('status', 'open')
            ->get();

        foreach ($shortPositions as $position) {
            if ($currentPrice >= $position->trailing_stop_price) {
                // トレーリングストップで決済（買い戻し）
                $buyResult = $this->exchangeClient->buy($symbol, $position->quantity, null); // 成行注文

                if ($buyResult['success']) {
                    // ショートポジションの損益: (entry_price - exit_price) * quantity
                    $profitLoss = ($position->entry_price - $buyResult['price']) * $position->quantity;

                    $position->update([
                        'exit_price' => $buyResult['price'],
                        'status' => 'closed',
                        'closed_at' => now(),
                        'profit_loss' => $profitLoss,
                    ]);

                    Log::info('Trailing stop triggered - Short position closed', [
                        'symbol' => $symbol,
                        'position_id' => $position->id,
                        'entry_price' => $position->entry_price,
                        'exit_price' => $buyResult['price'],
                        'trailing_stop' => $position->trailing_stop_price,
                        'profit_loss' => $profitLoss,
                        'percentage' => ($profitLoss / ($position->entry_price * $position->quantity)) * 100,
                    ]);

                    TradingLog::create([
                        'symbol' => $symbol,
                        'action' => 'trailing_stop_buy',
                        'quantity' => $position->quantity,
                        'price' => $buyResult['price'],
                        'message' => sprintf(
                            'トレーリングストップ決済 - ショート (エントリー: %.2f円 → 決済: %.2f円, 損益: %.4f円)',
                            $position->entry_price,
                            $buyResult['price'],
                            $profitLoss
                        ),
                        'executed_at' => now(),
                    ]);

                    // エグジット通知を送信（元の戦略名を使用）
                    $this->sendNotification(
                        'exit',
                        $symbol,
                        'short',
                        $buyResult['price'],
                        $position->quantity,
                        $profitLoss,
                        'トレーリングストップ到達',
                        $this->getPositionStrategyName($position)
                    );
                }
            }
        }
    }
}
