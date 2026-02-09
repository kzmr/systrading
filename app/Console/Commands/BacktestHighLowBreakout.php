<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BacktestHighLowBreakout extends Command
{
    protected $signature = 'trading:backtest-breakout
        {--symbol=BTC/JPY : Trading symbol}
        {--threshold=0.4 : Breakout threshold percentage}
        {--lookback=20 : Lookback period for high/low}
        {--stop-loss=1.0 : Stop loss percentage}
        {--initial-trailing=0.7 : Initial trailing stop percentage}
        {--trailing-offset=0.5 : Trailing stop offset percentage}
        {--max-positions=3 : Maximum positions per direction}
        {--csv= : Path to CSV file for price data}
        {--optimize : Run parameter optimization}';

    protected $description = 'Backtest HighLowBreakout Strategy with optional parameter optimization';

    private array $positions = [];
    private array $closedTrades = [];
    private int $nextPositionId = 1;

    // 手数料率（Maker: -0.01%, Taker: 0.05%）
    // 保守的にTaker手数料を想定
    private float $feeRate = 0.0005;

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
        $threshold = (float) $this->option('threshold');
        $lookback = (int) $this->option('lookback');
        $stopLoss = (float) $this->option('stop-loss');
        $initialTrailing = (float) $this->option('initial-trailing');
        $trailingOffset = (float) $this->option('trailing-offset');
        $maxPositions = (int) $this->option('max-positions');

        $this->info("\n=== HighLowBreakout Strategy Backtest ===");
        $this->info("Symbol: {$symbol}");
        $this->info("Breakout Threshold: {$threshold}%");
        $this->info("Lookback Period: {$lookback}");
        $this->info("Stop Loss: {$stopLoss}%");
        $this->info("Initial Trailing Stop: {$initialTrailing}%");
        $this->info("Trailing Offset: {$trailingOffset}%");
        $this->info("Max Positions: {$maxPositions}");

        $prices = $this->loadPriceHistory($symbol);

        if (count($prices) < $lookback + 1) {
            $this->error("Not enough price data.");
            return 1;
        }

        $this->info("Price data: " . count($prices) . " records");
        $this->info("Period: " . $prices[0]['recorded_at'] . " to " . end($prices)['recorded_at']);

        $result = $this->simulate($prices, $threshold, $lookback, $stopLoss, $initialTrailing, $trailingOffset, $maxPositions);

        $this->displayResults($result);

        return 0;
    }

    private function runOptimization(): int
    {
        $symbol = $this->option('symbol');
        $this->info("\n=== HighLowBreakout Strategy Optimization ===");
        $this->info("Symbol: {$symbol}");

        $prices = $this->loadPriceHistory($symbol);

        if (count($prices) < 100) {
            $this->error("Not enough price data for optimization.");
            return 1;
        }

        $this->info("Price data: " . count($prices) . " records");
        $this->info("Period: " . $prices[0]['recorded_at'] . " to " . end($prices)['recorded_at']);

        // パラメータ範囲
        $thresholds = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.8, 1.0];
        $lookbacks = [10, 15, 20, 30, 40, 60];
        $stopLosses = [0.5, 1.0, 1.5, 2.0];
        $initialTrailings = [0.3, 0.5, 0.7, 1.0];
        $trailingOffsets = [0.3, 0.5, 0.7, 1.0];
        $maxPositionsList = [1, 2, 3];

        $results = [];
        $totalCombinations = count($thresholds) * count($lookbacks) * count($stopLosses)
            * count($initialTrailings) * count($trailingOffsets) * count($maxPositionsList);

        $this->info("Testing {$totalCombinations} parameter combinations...\n");

        $progressBar = $this->output->createProgressBar($totalCombinations);
        $progressBar->start();

        foreach ($thresholds as $threshold) {
            foreach ($lookbacks as $lookback) {
                foreach ($stopLosses as $stopLoss) {
                    foreach ($initialTrailings as $initialTrailing) {
                        foreach ($trailingOffsets as $trailingOffset) {
                            foreach ($maxPositionsList as $maxPositions) {
                                // Reset state
                                $this->positions = [];
                                $this->closedTrades = [];
                                $this->nextPositionId = 1;

                                $result = $this->simulate(
                                    $prices, $threshold, $lookback, $stopLoss,
                                    $initialTrailing, $trailingOffset, $maxPositions
                                );

                                // 最低10取引以上のみ有効
                                if ($result['total_trades'] >= 10) {
                                    $results[] = [
                                        'threshold' => $threshold,
                                        'lookback' => $lookback,
                                        'stop_loss' => $stopLoss,
                                        'initial_trailing' => $initialTrailing,
                                        'trailing_offset' => $trailingOffset,
                                        'max_positions' => $maxPositions,
                                        'total_trades' => $result['total_trades'],
                                        'win_rate' => $result['win_rate'],
                                        'total_pnl' => $result['total_pnl'],
                                        'profit_factor' => $result['profit_factor'],
                                        'avg_pnl' => $result['avg_pnl'],
                                        'max_drawdown' => $result['max_drawdown'],
                                    ];
                                }

                                $progressBar->advance();
                            }
                        }
                    }
                }
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        if (empty($results)) {
            $this->warn("No valid results (need at least 10 trades per combination).");
            return 1;
        }

        $this->info("Valid combinations: " . count($results));

        // 純損益順でソート
        usort($results, fn($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);

        $this->info("\n=== Top 10 by Total P&L ===\n");
        $this->displayResultsTable(array_slice($results, 0, 10));

        // 勝率順
        usort($results, fn($a, $b) => $b['win_rate'] <=> $a['win_rate']);

        $this->info("\n=== Top 10 by Win Rate ===\n");
        $this->displayResultsTable(array_slice($results, 0, 10));

        // プロフィットファクター順
        usort($results, fn($a, $b) => $b['profit_factor'] <=> $a['profit_factor']);

        $this->info("\n=== Top 10 by Profit Factor ===\n");
        $this->displayResultsTable(array_slice($results, 0, 10));

        // シャープレシオ的な指標（リターン/リスク）
        usort($results, function($a, $b) {
            $scoreA = $a['max_drawdown'] != 0 ? $a['total_pnl'] / abs($a['max_drawdown']) : 0;
            $scoreB = $b['max_drawdown'] != 0 ? $b['total_pnl'] / abs($b['max_drawdown']) : 0;
            return $scoreB <=> $scoreA;
        });

        $this->info("\n=== Top 10 by Risk-Adjusted Return (P&L / Max Drawdown) ===\n");
        $this->displayResultsTable(array_slice($results, 0, 10));

        return 0;
    }

    private function displayResultsTable(array $results): void
    {
        $this->table(
            ['閾値%', 'LB', 'SL%', '初期TS%', 'TSオフセット%', '最大P', '取引数', '勝率%', '純損益', 'PF', 'MaxDD'],
            array_map(function ($r) {
                return [
                    $r['threshold'],
                    $r['lookback'],
                    $r['stop_loss'],
                    $r['initial_trailing'],
                    $r['trailing_offset'],
                    $r['max_positions'],
                    $r['total_trades'],
                    number_format($r['win_rate'], 1),
                    number_format($r['total_pnl'], 0),
                    number_format($r['profit_factor'], 2),
                    number_format($r['max_drawdown'], 0),
                ];
            }, $results)
        );
    }

    private function simulate(
        array $prices,
        float $threshold,
        int $lookback,
        float $stopLoss,
        float $initialTrailing,
        float $trailingOffset,
        int $maxPositions
    ): array {
        $this->positions = [];
        $this->closedTrades = [];
        $this->nextPositionId = 1;

        $equity = 0;
        $maxEquity = 0;
        $maxDrawdown = 0;

        for ($i = $lookback; $i < count($prices); $i++) {
            $currentPrice = $prices[$i]['price'];
            $currentTime = $prices[$i]['recorded_at'];

            // 直近価格データ
            $recentPrices = array_column(array_slice($prices, $i - $lookback, $lookback), 'price');
            $recentHigh = max($recentPrices);
            $recentLow = min($recentPrices);

            // 1. 損切りチェック
            $this->checkStopLoss($currentPrice, $stopLoss, $currentTime);

            // 2. トレーリングストップ更新とチェック
            $this->updateTrailingStop($currentPrice, $trailingOffset);
            $this->checkTrailingStop($currentPrice, $currentTime);

            // 3. ブレイクアウト判定
            $thresholdMultiplier = $threshold / 100;
            $isHighBreakout = $currentPrice > $recentHigh * (1 + $thresholdMultiplier);
            $isLowBreakdown = $currentPrice < $recentLow * (1 - $thresholdMultiplier);

            // 4. シグナル処理
            if ($isHighBreakout) {
                $this->processHighBreakout($currentPrice, $currentTime, $maxPositions, $stopLoss, $initialTrailing);
            } elseif ($isLowBreakdown) {
                $this->processLowBreakdown($currentPrice, $currentTime, $maxPositions, $stopLoss, $initialTrailing);
            }

            // ドローダウン計算
            $equity = array_sum(array_column($this->closedTrades, 'pnl'));
            if ($equity > $maxEquity) {
                $maxEquity = $equity;
            }
            $drawdown = $maxEquity - $equity;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        // 最終決済
        $finalPrice = end($prices)['price'];
        $finalTime = end($prices)['recorded_at'];
        foreach ($this->positions as &$position) {
            $this->closePosition($position, $finalPrice, $finalTime, 'backtest_end');
        }

        return $this->calculateStats($maxDrawdown);
    }

    private function checkStopLoss(float $currentPrice, float $stopLossPercent, string $currentTime): void
    {
        foreach ($this->positions as &$position) {
            if ($position['status'] !== 'open') continue;

            if ($position['side'] === 'long') {
                $stopPrice = $position['entry_price'] * (1 - $stopLossPercent / 100);
                if ($currentPrice <= $stopPrice) {
                    $this->closePosition($position, $currentPrice, $currentTime, 'stop_loss');
                }
            } else {
                $stopPrice = $position['entry_price'] * (1 + $stopLossPercent / 100);
                if ($currentPrice >= $stopPrice) {
                    $this->closePosition($position, $currentPrice, $currentTime, 'stop_loss');
                }
            }
        }
        $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');
    }

    private function updateTrailingStop(float $currentPrice, float $trailingOffset): void
    {
        foreach ($this->positions as &$position) {
            if ($position['status'] !== 'open') continue;

            if ($position['side'] === 'long') {
                // ロング: 現在価格からオフセット分下にトレーリングストップを設定
                $newTrailingStop = $currentPrice * (1 - $trailingOffset / 100);
                if ($newTrailingStop > $position['trailing_stop']) {
                    $position['trailing_stop'] = $newTrailingStop;
                }
            } else {
                // ショート: 現在価格からオフセット分上にトレーリングストップを設定
                $newTrailingStop = $currentPrice * (1 + $trailingOffset / 100);
                if ($newTrailingStop < $position['trailing_stop']) {
                    $position['trailing_stop'] = $newTrailingStop;
                }
            }
        }
    }

    private function checkTrailingStop(float $currentPrice, string $currentTime): void
    {
        foreach ($this->positions as &$position) {
            if ($position['status'] !== 'open') continue;

            if ($position['side'] === 'long' && $currentPrice <= $position['trailing_stop']) {
                $this->closePosition($position, $currentPrice, $currentTime, 'trailing_stop');
            } elseif ($position['side'] === 'short' && $currentPrice >= $position['trailing_stop']) {
                $this->closePosition($position, $currentPrice, $currentTime, 'trailing_stop');
            }
        }
        $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');
    }

    private function processHighBreakout(float $price, string $time, int $maxPositions, float $stopLoss, float $initialTrailing): void
    {
        $longCount = count(array_filter($this->positions, fn($p) => $p['side'] === 'long' && $p['status'] === 'open'));
        $shortPositions = array_filter($this->positions, fn($p) => $p['side'] === 'short' && $p['status'] === 'open');

        // ショート保有中なら全決済してロング
        if (!empty($shortPositions)) {
            foreach ($this->positions as &$position) {
                if ($position['side'] === 'short' && $position['status'] === 'open') {
                    $this->closePosition($position, $price, $time, 'reverse_breakout');
                }
            }
            $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');
            $this->openPosition('long', $price, $time, $initialTrailing);
        } elseif ($longCount < $maxPositions) {
            $this->openPosition('long', $price, $time, $initialTrailing);
        }
    }

    private function processLowBreakdown(float $price, string $time, int $maxPositions, float $stopLoss, float $initialTrailing): void
    {
        $shortCount = count(array_filter($this->positions, fn($p) => $p['side'] === 'short' && $p['status'] === 'open'));
        $longPositions = array_filter($this->positions, fn($p) => $p['side'] === 'long' && $p['status'] === 'open');

        if (!empty($longPositions)) {
            foreach ($this->positions as &$position) {
                if ($position['side'] === 'long' && $position['status'] === 'open') {
                    $this->closePosition($position, $price, $time, 'reverse_breakout');
                }
            }
            $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');
            $this->openPosition('short', $price, $time, $initialTrailing);
        } elseif ($shortCount < $maxPositions) {
            $this->openPosition('short', $price, $time, $initialTrailing);
        }
    }

    private function openPosition(string $side, float $price, string $time, float $initialTrailing): void
    {
        $trailingStop = $side === 'long'
            ? $price * (1 - $initialTrailing / 100)
            : $price * (1 + $initialTrailing / 100);

        $this->positions[] = [
            'id' => $this->nextPositionId++,
            'side' => $side,
            'entry_price' => $price,
            'entry_time' => $time,
            'trailing_stop' => $trailingStop,
            'quantity' => 1, // 正規化された数量
            'status' => 'open',
        ];
    }

    private function closePosition(array &$position, float $price, string $time, string $reason): void
    {
        if ($position['status'] === 'closed') return;

        $grossPnL = $position['side'] === 'long'
            ? $price - $position['entry_price']
            : $position['entry_price'] - $price;

        // 手数料控除（往復）
        $fee = ($position['entry_price'] + $price) * $this->feeRate;
        $pnl = $grossPnL - $fee;

        $position['exit_price'] = $price;
        $position['exit_time'] = $time;
        $position['gross_pnl'] = $grossPnL;
        $position['fee'] = $fee;
        $position['pnl'] = $pnl;
        $position['exit_reason'] = $reason;
        $position['status'] = 'closed';

        $this->closedTrades[] = $position;
    }

    private function calculateStats(float $maxDrawdown): array
    {
        $totalTrades = count($this->closedTrades);
        if ($totalTrades === 0) {
            return [
                'total_trades' => 0,
                'wins' => 0,
                'losses' => 0,
                'win_rate' => 0,
                'total_pnl' => 0,
                'avg_pnl' => 0,
                'profit_factor' => 0,
                'max_drawdown' => 0,
            ];
        }

        $wins = count(array_filter($this->closedTrades, fn($t) => $t['pnl'] > 0));
        $losses = $totalTrades - $wins;
        $winRate = ($wins / $totalTrades) * 100;

        $totalPnL = array_sum(array_column($this->closedTrades, 'pnl'));
        $avgPnL = $totalPnL / $totalTrades;

        $totalWin = array_sum(array_filter(array_column($this->closedTrades, 'pnl'), fn($p) => $p > 0));
        $totalLoss = abs(array_sum(array_filter(array_column($this->closedTrades, 'pnl'), fn($p) => $p <= 0)));
        $profitFactor = $totalLoss > 0 ? $totalWin / $totalLoss : ($totalWin > 0 ? 999 : 0);

        return [
            'trades' => $this->closedTrades,
            'total_trades' => $totalTrades,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $winRate,
            'total_pnl' => $totalPnL,
            'avg_pnl' => $avgPnL,
            'profit_factor' => $profitFactor,
            'max_drawdown' => $maxDrawdown,
        ];
    }

    private function loadPriceHistory(string $symbol): array
    {
        $csvPath = $this->option('csv');

        if ($csvPath && file_exists($csvPath)) {
            $this->info("Loading from CSV: {$csvPath}");
            $prices = [];
            $handle = fopen($csvPath, 'r');
            $header = fgetcsv($handle);

            while (($row = fgetcsv($handle)) !== false) {
                if ($row[1] === $symbol) {
                    $prices[] = [
                        'price' => (float) $row[2],
                        'recorded_at' => $row[3],
                    ];
                }
            }
            fclose($handle);

            $this->info("Loaded " . count($prices) . " records for {$symbol}");
            return $prices;
        }

        // Fallback to database
        return \App\Models\PriceHistory::where('symbol', $symbol)
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
        $this->info(sprintf("Win rate: %.2f%%", $result['win_rate']));
        $this->info(sprintf("Total P&L: %.2f", $result['total_pnl']));
        $this->info(sprintf("Avg P&L: %.4f", $result['avg_pnl']));
        $this->info(sprintf("Profit Factor: %.2f", $result['profit_factor']));
        $this->info(sprintf("Max Drawdown: %.2f", $result['max_drawdown']));

        if (!empty($result['trades'])) {
            $reasons = array_count_values(array_column($result['trades'], 'exit_reason'));
            $this->info("\n=== Exit Reasons ===");
            foreach ($reasons as $reason => $count) {
                $this->info("{$reason}: {$count}");
            }

            $this->info("\n=== Last 10 Trades ===");
            $recentTrades = array_slice($result['trades'], -10);
            foreach ($recentTrades as $trade) {
                $pnlStr = $trade['pnl'] >= 0 ? '+' . number_format($trade['pnl'], 2) : number_format($trade['pnl'], 2);
                $this->line(sprintf(
                    "[%s] %s: %.0f -> %.0f (%s) [%s]",
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
