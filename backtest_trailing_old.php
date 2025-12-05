<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// 現在の設定（トレーリングストップのみ旧方式）
$lookbackPeriod = 20;
$breakoutThreshold = 0.15; // 最適化済み
$initialTrailingStopPercent = 0.5; // 最適化済み
$trailingOffsetPercent = 0.5;
$stopLossPercent = 1.0;

echo "========================================\n";
echo "バックテスト実行 - 【適用前】直近20本ベース\n";
echo "========================================\n";
echo "ブレイクアウト閾値: {$breakoutThreshold}%\n";
echo "初期トレーリングS: {$initialTrailingStopPercent}%\n";
echo "トレーリングオフセット: {$trailingOffsetPercent}%\n";
echo "トレーリングS計算: 直近20本の高値・安値ベース（旧方式）⚠️\n";
echo "固定損切り: {$stopLossPercent}%\n";
echo "lookback_period: {$lookbackPeriod}本\n";
echo "========================================\n\n";

// 価格履歴データを取得
$prices = DB::table('price_history')
    ->where('symbol', 'XRP/JPY')
    ->orderBy('recorded_at', 'asc')
    ->get();

if ($prices->count() < $lookbackPeriod + 50) {
    echo "エラー: 価格データが不足しています（必要: " . ($lookbackPeriod + 50) . "本、利用可能: {$prices->count()}本）\n";
    exit(1);
}

echo "価格データ数: {$prices->count()}本\n";
echo "期間: {$prices->first()->recorded_at} ～ {$prices->last()->recorded_at}\n\n";

// バックテスト用の仮想ポジション
$positions = [];
$closedTrades = [];
$tradeId = 1;

// 各時点でのシミュレーション
for ($i = $lookbackPeriod; $i < $prices->count(); $i++) {
    $currentPrice = (float)$prices[$i]->price;
    $currentTime = $prices[$i]->recorded_at;

    // 過去N本の価格
    $historicalPrices = [];
    for ($j = $i - $lookbackPeriod; $j < $i; $j++) {
        $historicalPrices[] = (float)$prices[$j]->price;
    }

    $highestHigh = max($historicalPrices);
    $lowestLow = min($historicalPrices);

    // ブレイクアウト閾値
    $buyThreshold = $highestHigh * (1 + $breakoutThreshold / 100);
    $sellThreshold = $lowestLow * (1 - $breakoutThreshold / 100);

    // 既存ポジションの損切り・トレーリングストップチェック
    foreach ($positions as $key => $pos) {
        $shouldClose = false;
        $closeReason = '';

        if ($pos['side'] === 'long') {
            // ロングの固定損切り
            $stopLossPrice = $pos['entry_price'] * (1 - $stopLossPercent / 100);
            if ($currentPrice <= $stopLossPrice) {
                $shouldClose = true;
                $closeReason = 'stop_loss';
            }
            // トレーリングストップ
            elseif ($currentPrice <= $pos['trailing_stop']) {
                $shouldClose = true;
                $closeReason = 'trailing_stop';
            }
            // トレーリングストップの更新（旧方式: 直近20本の安値ベース）
            else {
                $newTrailingStop = $lowestLow * (1 - $trailingOffsetPercent / 100);
                if ($newTrailingStop > $pos['trailing_stop']) {
                    $positions[$key]['trailing_stop'] = $newTrailingStop;
                }
            }
        } else { // short
            // ショートの固定損切り
            $stopLossPrice = $pos['entry_price'] * (1 + $stopLossPercent / 100);
            if ($currentPrice >= $stopLossPrice) {
                $shouldClose = true;
                $closeReason = 'stop_loss';
            }
            // トレーリングストップ
            elseif ($currentPrice >= $pos['trailing_stop']) {
                $shouldClose = true;
                $closeReason = 'trailing_stop';
            }
            // トレーリングストップの更新（旧方式: 直近20本の高値ベース）
            else {
                $newTrailingStop = $highestHigh * (1 + $trailingOffsetPercent / 100);
                if ($newTrailingStop < $pos['trailing_stop']) {
                    $positions[$key]['trailing_stop'] = $newTrailingStop;
                }
            }
        }

        if ($shouldClose) {
            $profitLoss = $pos['side'] === 'long'
                ? ($currentPrice - $pos['entry_price']) * $pos['quantity']
                : ($pos['entry_price'] - $currentPrice) * $pos['quantity'];

            $closedTrades[] = [
                'id' => $pos['id'],
                'side' => $pos['side'],
                'entry_price' => $pos['entry_price'],
                'exit_price' => $currentPrice,
                'entry_time' => $pos['entry_time'],
                'exit_time' => $currentTime,
                'profit_loss' => $profitLoss,
                'reason' => $closeReason,
            ];

            unset($positions[$key]);
        }
    }

    // 新規エントリーシグナル
    $longCount = count(array_filter($positions, fn($p) => $p['side'] === 'long'));
    $shortCount = count(array_filter($positions, fn($p) => $p['side'] === 'short'));

    // 高値ブレイクアウト（買いシグナル）
    if ($currentPrice > $buyThreshold) {
        if ($shortCount > 0) {
            // ショートポジションがある場合は全決済
            foreach ($positions as $key => $pos) {
                if ($pos['side'] === 'short') {
                    $profitLoss = ($pos['entry_price'] - $currentPrice) * $pos['quantity'];
                    $closedTrades[] = [
                        'id' => $pos['id'],
                        'side' => $pos['side'],
                        'entry_price' => $pos['entry_price'],
                        'exit_price' => $currentPrice,
                        'entry_time' => $pos['entry_time'],
                        'exit_time' => $currentTime,
                        'profit_loss' => $profitLoss,
                        'reason' => 'reversal_signal',
                    ];
                    unset($positions[$key]);
                }
            }
            // ロング新規エントリー
            $positions[] = [
                'id' => $tradeId++,
                'side' => 'long',
                'entry_price' => $currentPrice,
                'entry_time' => $currentTime,
                'quantity' => 0.01,
                'trailing_stop' => $currentPrice * (1 - $initialTrailingStopPercent / 100),
            ];
        } elseif ($longCount < 3) {
            // ロング追加エントリー
            $positions[] = [
                'id' => $tradeId++,
                'side' => 'long',
                'entry_price' => $currentPrice,
                'entry_time' => $currentTime,
                'quantity' => 0.01,
                'trailing_stop' => $currentPrice * (1 - $initialTrailingStopPercent / 100),
            ];
        }
    }

    // 安値ブレイクダウン（売りシグナル）
    if ($currentPrice < $sellThreshold) {
        if ($longCount > 0) {
            // ロングポジションがある場合は全決済
            foreach ($positions as $key => $pos) {
                if ($pos['side'] === 'long') {
                    $profitLoss = ($currentPrice - $pos['entry_price']) * $pos['quantity'];
                    $closedTrades[] = [
                        'id' => $pos['id'],
                        'side' => $pos['side'],
                        'entry_price' => $pos['entry_price'],
                        'exit_price' => $currentPrice,
                        'entry_time' => $pos['entry_time'],
                        'exit_time' => $currentTime,
                        'profit_loss' => $profitLoss,
                        'reason' => 'reversal_signal',
                    ];
                    unset($positions[$key]);
                }
            }
            // ショート新規エントリー
            $positions[] = [
                'id' => $tradeId++,
                'side' => 'short',
                'entry_price' => $currentPrice,
                'entry_time' => $currentTime,
                'quantity' => 0.01,
                'trailing_stop' => $currentPrice * (1 + $initialTrailingStopPercent / 100),
            ];
        } elseif ($shortCount < 3) {
            // ショート追加エントリー
            $positions[] = [
                'id' => $tradeId++,
                'side' => 'short',
                'entry_price' => $currentPrice,
                'entry_time' => $currentTime,
                'quantity' => 0.01,
                'trailing_stop' => $currentPrice * (1 + $initialTrailingStopPercent / 100),
            ];
        }
    }
}

// 結果集計
echo "\n========================================\n";
echo "バックテスト結果\n";
echo "========================================\n\n";

$totalTrades = count($closedTrades);
$winningTrades = array_filter($closedTrades, fn($t) => $t['profit_loss'] > 0);
$losingTrades = array_filter($closedTrades, fn($t) => $t['profit_loss'] <= 0);
$winCount = count($winningTrades);
$lossCount = count($losingTrades);
$winRate = $totalTrades > 0 ? ($winCount / $totalTrades) * 100 : 0;

$totalProfit = array_sum(array_column($closedTrades, 'profit_loss'));
$avgProfit = $totalTrades > 0 ? $totalProfit / $totalTrades : 0;
$avgWin = $winCount > 0 ? array_sum(array_column($winningTrades, 'profit_loss')) / $winCount : 0;
$avgLoss = $lossCount > 0 ? array_sum(array_column($losingTrades, 'profit_loss')) / $lossCount : 0;

echo "総トレード数: {$totalTrades}件\n";
echo "勝ちトレード: {$winCount}件\n";
echo "負けトレード: {$lossCount}件\n";
echo "勝率: " . number_format($winRate, 2) . "%\n\n";

echo "総損益: " . number_format($totalProfit, 4) . "円\n";
echo "平均損益: " . number_format($avgProfit, 4) . "円\n";
echo "平均利益: " . number_format($avgWin, 4) . "円\n";
echo "平均損失: " . number_format($avgLoss, 4) . "円\n";
echo "プロフィットファクター: " . ($avgLoss != 0 ? number_format(abs($avgWin / $avgLoss), 2) : 'N/A') . "\n\n";

// 決済理由別集計
$reasonStats = [];
foreach ($closedTrades as $trade) {
    $reason = $trade['reason'];
    if (!isset($reasonStats[$reason])) {
        $reasonStats[$reason] = ['count' => 0, 'profit' => 0];
    }
    $reasonStats[$reason]['count']++;
    $reasonStats[$reason]['profit'] += $trade['profit_loss'];
}

echo "--- 決済理由別統計 ---\n";
foreach ($reasonStats as $reason => $stats) {
    $reasonName = [
        'stop_loss' => '固定損切り',
        'trailing_stop' => 'トレーリングS',
        'reversal_signal' => '逆方向シグナル',
    ][$reason] ?? $reason;

    echo "{$reasonName}: {$stats['count']}件 (損益: " . number_format($stats['profit'], 4) . "円)\n";
}

echo "\n========================================\n";
