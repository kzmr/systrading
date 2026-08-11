<?php

namespace App\Console\Commands;

use App\Models\PriceHistory;
use Illuminate\Console\Command;

class BacktestRSIStrategy extends Command
{
    protected $signature = 'trading:backtest-rsi
        {--symbol=XRP/JPY : Trading symbol}
        {--rsi-period=14 : RSI calculation period}
        {--rsi-oversold=30 : RSI oversold threshold for long entry}
        {--rsi-overbought=70 : RSI overbought threshold for short entry}
        {--rsi-exit-long=50 : RSI threshold to exit long positions}
        {--rsi-exit-short=50 : RSI threshold to exit short positions}
        {--max-hold=60 : Maximum hold time in minutes}
        {--stop-loss=1.0 : Stop loss percentage}
        {--initial-trailing=0 : Initial trailing stop percentage (0=disabled)}
        {--trailing-offset=0 : Trailing stop offset percentage (0=disabled)}
        {--trend-ma-period=0 : Trend filter MA period (0=disabled)}
        {--trend-threshold=0.3 : Trend filter deviation threshold percentage}
        {--cooldown=0 : Cooldown minutes after a losing trade (0=disabled)}
        {--optimize : Run parameter optimization}
        {--csv= : Path to CSV file for price data}';

    protected $description = 'Backtest RSI Contrarian Strategy with optional parameter optimization';

    public function handle()
    {
        if ($this->option('optimize')) {
            return $this->runOptimization();
        }

        return $this->runSingleBacktest();
    }

    private function runSingleBacktest(): int
    {
        $symbol = $this->option('symbol');
        $rsiPeriod = (int) $this->option('rsi-period');
        $rsiOversold = (float) $this->option('rsi-oversold');
        $rsiOverbought = (float) $this->option('rsi-overbought');
        $rsiExitLong = (float) $this->option('rsi-exit-long');
        $rsiExitShort = (float) $this->option('rsi-exit-short');
        $maxHold = (int) $this->option('max-hold');
        $stopLoss = (float) $this->option('stop-loss');
        $initialTrailing = (float) $this->option('initial-trailing');
        $trailingOffset = (float) $this->option('trailing-offset');
        $trendMaPeriod = (int) $this->option('trend-ma-period');
        $trendThreshold = (float) $this->option('trend-threshold');
        $cooldownMinutes = (int) $this->option('cooldown');

        $this->info("\n=== RSI Contrarian Strategy Backtest ===");
        $this->info("Symbol: {$symbol}");
        $this->info("RSI Period: {$rsiPeriod}");
        $this->info("RSI Oversold (Long Entry): {$rsiOversold}");
        $this->info("RSI Overbought (Short Entry): {$rsiOverbought}");
        $this->info("RSI Exit Long: {$rsiExitLong}");
        $this->info("RSI Exit Short: {$rsiExitShort}");
        $this->info("Max Hold: {$maxHold} minutes");
        $this->info("Stop Loss: {$stopLoss}%");
        $this->info("Initial Trailing Stop: " . ($initialTrailing > 0 ? "{$initialTrailing}%" : 'OFF'));
        $this->info("Trailing Offset: " . ($trailingOffset > 0 ? "{$trailingOffset}%" : 'OFF'));
        $this->info("Trend Filter: " . ($trendMaPeriod > 0 ? "MA{$trendMaPeriod} / {$trendThreshold}%" : 'OFF'));
        $this->info("Cooldown: " . ($cooldownMinutes > 0 ? "{$cooldownMinutes} minutes" : 'OFF'));

        $prices = $this->loadPriceHistory($symbol);
        $result = $this->simulate($prices, $rsiPeriod, $rsiOversold, $rsiOverbought, $rsiExitLong, $rsiExitShort, $maxHold, $stopLoss, $initialTrailing, $trailingOffset, $trendMaPeriod, $trendThreshold, $cooldownMinutes);

        $this->displayResults($result);

        return 0;
    }

    private function runOptimization(): int
    {
        $symbol = $this->option('symbol');
        $this->info("\n=== RSI Strategy Parameter Optimization ===");
        $this->info("Symbol: {$symbol}");

        $prices = $this->loadPriceHistory($symbol);

        if (count($prices) < 100) {
            $this->error("Not enough price data for optimization.");
            return 1;
        }

        $this->info("Price data loaded: " . count($prices) . " records");
        $this->info("Period: " . $prices[0]['recorded_at'] . " to " . end($prices)['recorded_at']);

        // トレーリングストップはCLIで指定した値を固定して最適化する
        $optInitialTrailing = (float) $this->option('initial-trailing');
        $optTrailingOffset = (float) $this->option('trailing-offset');
        $this->info("Initial Trailing Stop: " . ($optInitialTrailing > 0 ? "{$optInitialTrailing}%" : 'OFF'));
        $this->info("Trailing Offset: " . ($optTrailingOffset > 0 ? "{$optTrailingOffset}%" : 'OFF'));
        $optTrendMaPeriod = (int) $this->option('trend-ma-period');
        $optTrendThreshold = (float) $this->option('trend-threshold');
        $this->info("Trend Filter: " . ($optTrendMaPeriod > 0 ? "MA{$optTrendMaPeriod} / {$optTrendThreshold}%" : 'OFF'));
        $optCooldown = (int) $this->option('cooldown');
        $this->info("Cooldown: " . ($optCooldown > 0 ? "{$optCooldown} minutes" : 'OFF'));

        // パラメータ範囲
        $rsiPeriods = [14, 20, 30, 40, 60];
        $oversoldValues = [20, 25, 30, 35];
        $overboughtValues = [65, 70, 75, 80];
        $exitLongValues = [45, 50, 55, 60];
        $exitShortValues = [40, 45, 50, 55];
        $maxHoldValues = [30, 60, 120];
        $stopLossValues = [0.5, 1.0, 1.5];

        $results = [];
        $totalCombinations = count($rsiPeriods) * count($oversoldValues) * count($overboughtValues)
            * count($exitLongValues) * count($exitShortValues) * count($maxHoldValues) * count($stopLossValues);

        $this->info("Testing {$totalCombinations} parameter combinations...\n");

        $progressBar = $this->output->createProgressBar($totalCombinations);
        $progressBar->start();

        foreach ($rsiPeriods as $rsiPeriod) {
            foreach ($oversoldValues as $oversold) {
                foreach ($overboughtValues as $overbought) {
                    foreach ($exitLongValues as $exitLong) {
                        foreach ($exitShortValues as $exitShort) {
                            foreach ($maxHoldValues as $maxHold) {
                                foreach ($stopLossValues as $stopLoss) {
                                    $result = $this->simulate(
                                        $prices, $rsiPeriod, $oversold, $overbought,
                                        $exitLong, $exitShort, $maxHold, $stopLoss,
                                        $optInitialTrailing, $optTrailingOffset,
                                        $optTrendMaPeriod, $optTrendThreshold, $optCooldown
                                    );

                                    if ($result['total_trades'] >= 10) {
                                        $results[] = [
                                            'rsi_period' => $rsiPeriod,
                                            'oversold' => $oversold,
                                            'overbought' => $overbought,
                                            'exit_long' => $exitLong,
                                            'exit_short' => $exitShort,
                                            'max_hold' => $maxHold,
                                            'stop_loss' => $stopLoss,
                                            'total_trades' => $result['total_trades'],
                                            'win_rate' => $result['win_rate'],
                                            'total_pnl' => $result['total_pnl'],
                                            'profit_factor' => $result['profit_factor'],
                                            'avg_pnl' => $result['avg_pnl'],
                                        ];
                                    }

                                    $progressBar->advance();
                                }
                            }
                        }
                    }
                }
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        if (empty($results)) {
            $this->warn("No valid results found (need at least 10 trades per combination).");
            return 1;
        }

        // 結果をソート（純損益順）
        usort($results, fn($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);

        $this->info("=== Top 10 Parameter Combinations (by Total P&L) ===\n");
        $this->table(
            ['RSI期間', '売られすぎ', '買われすぎ', 'Exit Long', 'Exit Short', '最大保有', '損切り', '取引数', '勝率%', '純損益', 'PF'],
            array_map(function ($r) {
                return [
                    $r['rsi_period'],
                    $r['oversold'],
                    $r['overbought'],
                    $r['exit_long'],
                    $r['exit_short'],
                    $r['max_hold'],
                    $r['stop_loss'] . '%',
                    $r['total_trades'],
                    number_format($r['win_rate'], 1),
                    number_format($r['total_pnl'], 2),
                    number_format($r['profit_factor'], 2),
                ];
            }, array_slice($results, 0, 10))
        );

        // 勝率順でもソート
        usort($results, fn($a, $b) => $b['win_rate'] <=> $a['win_rate']);

        $this->info("\n=== Top 10 Parameter Combinations (by Win Rate) ===\n");
        $this->table(
            ['RSI期間', '売られすぎ', '買われすぎ', 'Exit Long', 'Exit Short', '最大保有', '損切り', '取引数', '勝率%', '純損益', 'PF'],
            array_map(function ($r) {
                return [
                    $r['rsi_period'],
                    $r['oversold'],
                    $r['overbought'],
                    $r['exit_long'],
                    $r['exit_short'],
                    $r['max_hold'],
                    $r['stop_loss'] . '%',
                    $r['total_trades'],
                    number_format($r['win_rate'], 1),
                    number_format($r['total_pnl'], 2),
                    number_format($r['profit_factor'], 2),
                ];
            }, array_slice($results, 0, 10))
        );

        // プロフィットファクター順
        usort($results, fn($a, $b) => $b['profit_factor'] <=> $a['profit_factor']);

        $this->info("\n=== Top 10 Parameter Combinations (by Profit Factor) ===\n");
        $this->table(
            ['RSI期間', '売られすぎ', '買われすぎ', 'Exit Long', 'Exit Short', '最大保有', '損切り', '取引数', '勝率%', '純損益', 'PF'],
            array_map(function ($r) {
                return [
                    $r['rsi_period'],
                    $r['oversold'],
                    $r['overbought'],
                    $r['exit_long'],
                    $r['exit_short'],
                    $r['max_hold'],
                    $r['stop_loss'] . '%',
                    $r['total_trades'],
                    number_format($r['win_rate'], 1),
                    number_format($r['total_pnl'], 2),
                    number_format($r['profit_factor'], 2),
                ];
            }, array_slice($results, 0, 10))
        );

        return 0;
    }

    private function simulate(
        array $prices,
        int $rsiPeriod,
        float $rsiOversold,
        float $rsiOverbought,
        float $rsiExitLong,
        float $rsiExitShort,
        int $maxHold,
        float $stopLoss,
        float $initialTrailing = 0.0,
        float $trailingOffset = 0.0,
        int $trendMaPeriod = 0,
        float $trendThreshold = 0.0,
        int $cooldownMinutes = 0
    ): array {
        $trades = [];
        $position = null;
        $wins = 0;
        $losses = 0;
        $totalPnL = 0;

        // 手数料（片道0.05%のTaker想定、指値なら-0.01%だが保守的に）
        $feeRate = 0.0005;

        // トレーリングストップの有効判定
        // 本番(OrderExecutor)ではトレーリングと損切りのうち保護的な方が決済価格になる
        $trailingEnabled = $initialTrailing > 0 && $trailingOffset > 0;

        // トレンドフィルターの有効判定
        // 本番(RSIContrarianStrategy)は下落トレンド中の買いと上昇トレンド中の売りを禁止する
        $trendFilterEnabled = $trendMaPeriod > 0 && $trendThreshold > 0;

        // トレンド判定に必要な本数が揃うまで開始しない
        $startIndex = max($rsiPeriod + 1, $trendFilterEnabled ? $trendMaPeriod - 1 : 0);

        // クールダウンの有効判定
        // 本番(RSIContrarianStrategy)は負けトレード後、一定時間エントリーを停止する
        $cooldownEnabled = $cooldownMinutes > 0;
        $lastLossAt = null;

        // フィルターで見送ったシグナル数（効果の可視化用）
        $skippedByTrend = 0;
        $skippedByCooldown = 0;

        for ($i = $startIndex; $i < count($prices); $i++) {
            $currentPrice = $prices[$i]['price'];
            $timestamp = $prices[$i]['recorded_at'];

            // RSI計算
            $rsi = $this->calculateRSI(array_slice($prices, $i - $rsiPeriod, $rsiPeriod + 1), $rsiPeriod);

            if ($rsi === null) {
                continue;
            }

            // ポジション管理
            if ($position) {
                $holdMinutes = (strtotime($timestamp) - strtotime($position['entry_time'])) / 60;
                $shouldClose = false;
                $exitReason = '';

                if ($position['side'] === 'long') {
                    // トレーリングストップ更新（利益方向のみ追跡）
                    if ($trailingEnabled) {
                        $newTrailingStop = $currentPrice * (1 - $trailingOffset / 100);
                        if ($newTrailingStop > $position['trailing_stop']) {
                            $position['trailing_stop'] = $newTrailingStop;
                        }
                    }

                    // 決済価格 = トレーリングと損切りのうち保護的な方（本番の calculateExitPrice と同じ）
                    $stopLossPrice = $position['entry_price'] * (1 - $stopLoss / 100);
                    $exitLevel = $trailingEnabled
                        ? max($position['trailing_stop'], $stopLossPrice)
                        : $stopLossPrice;

                    if ($currentPrice <= $exitLevel) {
                        $shouldClose = true;
                        $exitReason = ($trailingEnabled && $position['trailing_stop'] >= $stopLossPrice)
                            ? 'trailing_stop'
                            : 'stop_loss';
                    }
                    // RSI利確チェック
                    elseif ($rsi >= $rsiExitLong) {
                        $shouldClose = true;
                        $exitReason = 'rsi_exit';
                    }
                    // タイムアウトチェック
                    elseif ($holdMinutes >= $maxHold) {
                        $shouldClose = true;
                        $exitReason = 'timeout';
                    }

                    if ($shouldClose) {
                        $grossPnL = $currentPrice - $position['entry_price'];
                        $fee = ($position['entry_price'] + $currentPrice) * $feeRate;
                        $pnl = $grossPnL - $fee;
                        $totalPnL += $pnl;
                        if ($pnl > 0) $wins++; else $losses++;
                        // クールダウン判定用（本番は手数料控除前で負けを判定する）
                        if ($grossPnL < 0) {
                            $lastLossAt = strtotime($timestamp);
                        }
                        $trades[] = [
                            'entry_time' => $position['entry_time'],
                            'exit_time' => $timestamp,
                            'side' => 'long',
                            'entry_price' => $position['entry_price'],
                            'exit_price' => $currentPrice,
                            'pnl' => $pnl,
                            'exit_reason' => $exitReason,
                        ];
                        $position = null;
                    }
                } else { // short
                    // トレーリングストップ更新（利益方向のみ追跡）
                    if ($trailingEnabled) {
                        $newTrailingStop = $currentPrice * (1 + $trailingOffset / 100);
                        if ($newTrailingStop < $position['trailing_stop']) {
                            $position['trailing_stop'] = $newTrailingStop;
                        }
                    }

                    // 決済価格 = トレーリングと損切りのうち保護的な方（本番の calculateExitPrice と同じ）
                    $stopLossPrice = $position['entry_price'] * (1 + $stopLoss / 100);
                    $exitLevel = $trailingEnabled
                        ? min($position['trailing_stop'], $stopLossPrice)
                        : $stopLossPrice;

                    if ($currentPrice >= $exitLevel) {
                        $shouldClose = true;
                        $exitReason = ($trailingEnabled && $position['trailing_stop'] <= $stopLossPrice)
                            ? 'trailing_stop'
                            : 'stop_loss';
                    }
                    elseif ($rsi <= $rsiExitShort) {
                        $shouldClose = true;
                        $exitReason = 'rsi_exit';
                    }
                    elseif ($holdMinutes >= $maxHold) {
                        $shouldClose = true;
                        $exitReason = 'timeout';
                    }

                    if ($shouldClose) {
                        $grossPnL = $position['entry_price'] - $currentPrice;
                        $fee = ($position['entry_price'] + $currentPrice) * $feeRate;
                        $pnl = $grossPnL - $fee;
                        $totalPnL += $pnl;
                        if ($pnl > 0) $wins++; else $losses++;
                        // クールダウン判定用（本番は手数料控除前で負けを判定する）
                        if ($grossPnL < 0) {
                            $lastLossAt = strtotime($timestamp);
                        }
                        $trades[] = [
                            'entry_time' => $position['entry_time'],
                            'exit_time' => $timestamp,
                            'side' => 'short',
                            'entry_price' => $position['entry_price'],
                            'exit_price' => $currentPrice,
                            'pnl' => $pnl,
                            'exit_reason' => $exitReason,
                        ];
                        $position = null;
                    }
                }
            }

            // 新規エントリー判定（ポジションがない場合のみ）
            if (!$position) {
                // トレンド判定（本番 TradingStrategy::detectTrend と同一ロジック）
                // 移動平均からの乖離率で up / down / range を判定する
                $trend = 'range';
                if ($trendFilterEnabled) {
                    $maPrices = array_column(
                        array_slice($prices, $i - $trendMaPeriod + 1, $trendMaPeriod),
                        'price'
                    );
                    $ma = array_sum($maPrices) / count($maPrices);
                    $deviation = ($currentPrice - $ma) / $ma * 100;

                    if ($deviation > $trendThreshold) {
                        $trend = 'up';
                    } elseif ($deviation < -$trendThreshold) {
                        $trend = 'down';
                    }
                }

                $wantLong = $rsi < $rsiOversold;
                $wantShort = $rsi > $rsiOverbought;

                // 下落トレンド中の買い / 上昇トレンド中の売りは見送る
                if ($wantLong && $trendFilterEnabled && $trend === 'down') {
                    $wantLong = false;
                    $skippedByTrend++;
                }
                if ($wantShort && $trendFilterEnabled && $trend === 'up') {
                    $wantShort = false;
                    $skippedByTrend++;
                }

                // クールダウン判定（本番 RSIContrarianStrategy::isInCooldown と同一）
                // 直近の負けトレードから一定時間はエントリーしない
                // 本番は手数料控除前の profit_loss で判定するため gross で比較する
                if (($wantLong || $wantShort) && $cooldownEnabled && $lastLossAt !== null
                    && (strtotime($timestamp) - $lastLossAt) < $cooldownMinutes * 60) {
                    $wantLong = false;
                    $wantShort = false;
                    $skippedByCooldown++;
                }

                if ($wantLong) {
                    // 売られすぎ → ロングエントリー
                    $position = [
                        'side' => 'long',
                        'entry_price' => $currentPrice,
                        'entry_time' => $timestamp,
                        'trailing_stop' => $trailingEnabled
                            ? $currentPrice * (1 - $initialTrailing / 100)
                            : null,
                    ];
                } elseif ($wantShort) {
                    // 買われすぎ → ショートエントリー
                    $position = [
                        'side' => 'short',
                        'entry_price' => $currentPrice,
                        'entry_time' => $timestamp,
                        'trailing_stop' => $trailingEnabled
                            ? $currentPrice * (1 + $initialTrailing / 100)
                            : null,
                    ];
                }
            }
        }

        // 未決済ポジションがあれば決済
        if ($position) {
            $currentPrice = end($prices)['price'];
            $timestamp = end($prices)['recorded_at'];
            if ($position['side'] === 'long') {
                $grossPnL = $currentPrice - $position['entry_price'];
            } else {
                $grossPnL = $position['entry_price'] - $currentPrice;
            }
            $fee = ($position['entry_price'] + $currentPrice) * $feeRate;
            $pnl = $grossPnL - $fee;
            $totalPnL += $pnl;
            if ($pnl > 0) $wins++; else $losses++;
            $trades[] = [
                'entry_time' => $position['entry_time'],
                'exit_time' => $timestamp,
                'side' => $position['side'],
                'entry_price' => $position['entry_price'],
                'exit_price' => $currentPrice,
                'pnl' => $pnl,
                'exit_reason' => 'end_of_data',
            ];
        }

        $totalTrades = count($trades);
        $winRate = $totalTrades > 0 ? ($wins / $totalTrades) * 100 : 0;
        $avgPnL = $totalTrades > 0 ? $totalPnL / $totalTrades : 0;

        $totalWin = array_sum(array_filter(array_column($trades, 'pnl'), fn($p) => $p > 0));
        $totalLoss = abs(array_sum(array_filter(array_column($trades, 'pnl'), fn($p) => $p <= 0)));
        $profitFactor = $totalLoss > 0 ? $totalWin / $totalLoss : ($totalWin > 0 ? 999 : 0);

        return [
            'trades' => $trades,
            'wins' => $wins,
            'losses' => $losses,
            'total_trades' => $totalTrades,
            'win_rate' => $winRate,
            'total_pnl' => $totalPnL,
            'avg_pnl' => $avgPnL,
            'profit_factor' => $profitFactor,
            'skipped_by_trend' => $skippedByTrend,
            'skipped_by_cooldown' => $skippedByCooldown,
        ];
    }

    private function calculateRSI(array $prices, int $period): ?float
    {
        if (count($prices) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($prices); $i++) {
            $change = $prices[$i]['price'] - $prices[$i - 1]['price'];
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        $avgGain = array_sum(array_slice($gains, -$period)) / $period;
        $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

        if ($avgLoss == 0) {
            return 100;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    private function loadPriceHistory(string $symbol): array
    {
        $csvPath = $this->option('csv');

        if ($csvPath && file_exists($csvPath)) {
            $this->info("Loading from CSV: {$csvPath}");
            $prices = [];
            $handle = fopen($csvPath, 'r');
            $header = fgetcsv($handle); // Skip header

            while (($row = fgetcsv($handle)) !== false) {
                if ($row[1] === $symbol) {
                    $prices[] = [
                        'price' => (float) $row[2],
                        'recorded_at' => $row[3],
                    ];
                }
            }
            fclose($handle);

            return $prices;
        }

        return PriceHistory::where('symbol', $symbol)
            ->orderBy('recorded_at')
            ->get(['price', 'recorded_at'])
            ->toArray();
    }

    private function displayResults(array $result): void
    {
        $this->info("\n=== Results ===");
        $this->info("Total trades: {$result['total_trades']}");
        $this->info("Wins: {$result['wins']}");
        $this->info("Losses: {$result['losses']}");

        if (!empty($result['skipped_by_trend'])) {
            $this->info("Skipped by trend filter: {$result['skipped_by_trend']}");
        }

        if (!empty($result['skipped_by_cooldown'])) {
            $this->info("Skipped by cooldown: {$result['skipped_by_cooldown']}");
        }

        if ($result['total_trades'] > 0) {
            $this->info(sprintf("Win rate: %.2f%%", $result['win_rate']));
            $this->info(sprintf("Total P&L: %.4f", $result['total_pnl']));
            $this->info(sprintf("Avg P&L: %.4f", $result['avg_pnl']));
            $this->info(sprintf("Profit Factor: %.2f", $result['profit_factor']));
        }

        // 決済理由の内訳
        if (!empty($result['trades'])) {
            $reasons = array_count_values(array_column($result['trades'], 'exit_reason'));
            $this->info("\n=== Exit Reasons ===");
            foreach ($reasons as $reason => $count) {
                $this->info("{$reason}: {$count}");
            }

            // 最新10件の取引を表示
            $this->info("\n=== Last 10 Trades ===");
            $recentTrades = array_slice($result['trades'], -10);
            foreach ($recentTrades as $trade) {
                $pnlStr = $trade['pnl'] >= 0 ? '+' . number_format($trade['pnl'], 4) : number_format($trade['pnl'], 4);
                $this->line(sprintf(
                    "[%s] %s: %.3f -> %.3f (%s) [%s]",
                    $trade['entry_time'],
                    strtoupper($trade['side']),
                    $trade['entry_price'],
                    $trade['exit_price'],
                    $pnlStr,
                    $trade['exit_reason']
                ));
            }
        }
    }
}
