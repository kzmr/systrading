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

        $this->info("\n=== RSI Contrarian Strategy Backtest ===");
        $this->info("Symbol: {$symbol}");
        $this->info("RSI Period: {$rsiPeriod}");
        $this->info("RSI Oversold (Long Entry): {$rsiOversold}");
        $this->info("RSI Overbought (Short Entry): {$rsiOverbought}");
        $this->info("RSI Exit Long: {$rsiExitLong}");
        $this->info("RSI Exit Short: {$rsiExitShort}");
        $this->info("Max Hold: {$maxHold} minutes");
        $this->info("Stop Loss: {$stopLoss}%");

        $prices = $this->loadPriceHistory($symbol);
        $result = $this->simulate($prices, $rsiPeriod, $rsiOversold, $rsiOverbought, $rsiExitLong, $rsiExitShort, $maxHold, $stopLoss);

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
                                        $exitLong, $exitShort, $maxHold, $stopLoss
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
        float $stopLoss
    ): array {
        $trades = [];
        $position = null;
        $wins = 0;
        $losses = 0;
        $totalPnL = 0;

        // 手数料（片道0.05%のTaker想定、指値なら-0.01%だが保守的に）
        $feeRate = 0.0005;

        for ($i = $rsiPeriod + 1; $i < count($prices); $i++) {
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
                    // 損切りチェック
                    $stopLossPrice = $position['entry_price'] * (1 - $stopLoss / 100);
                    if ($currentPrice <= $stopLossPrice) {
                        $shouldClose = true;
                        $exitReason = 'stop_loss';
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
                    $stopLossPrice = $position['entry_price'] * (1 + $stopLoss / 100);
                    if ($currentPrice >= $stopLossPrice) {
                        $shouldClose = true;
                        $exitReason = 'stop_loss';
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
                if ($rsi < $rsiOversold) {
                    // 売られすぎ → ロングエントリー
                    $position = [
                        'side' => 'long',
                        'entry_price' => $currentPrice,
                        'entry_time' => $timestamp,
                    ];
                } elseif ($rsi > $rsiOverbought) {
                    // 買われすぎ → ショートエントリー
                    $position = [
                        'side' => 'short',
                        'entry_price' => $currentPrice,
                        'entry_time' => $timestamp,
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
