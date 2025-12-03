<?php

namespace App\Console\Commands;

use App\Models\PriceHistory;
use Illuminate\Console\Command;

class BacktestStrategy extends Command
{
    protected $signature = 'trading:backtest
                            {threshold=0.5 : Breakout threshold percentage (e.g., 0.5 for 0.5%)}
                            {--lookback=20 : Lookback period for high/low}
                            {--stop-loss=1.0 : Stop loss percentage}
                            {--trailing-offset=0.3 : Trailing stop offset percentage}
                            {--max-positions=3 : Maximum positions per direction}
                            {--spread=0.1 : Maximum spread percentage}';

    protected $description = 'Backtest trading strategy using historical price data';

    private array $positions = [];
    private array $closedTrades = [];
    private int $nextPositionId = 1;

    public function handle()
    {
        $threshold = (float) $this->argument('threshold');
        $lookbackPeriod = (int) $this->option('lookback');
        $stopLossPercent = (float) $this->option('stop-loss');
        $trailingOffset = (float) $this->option('trailing-offset');
        $maxPositions = (int) $this->option('max-positions');
        $maxSpreadPercent = (float) $this->option('spread');

        $this->info("========================================");
        $this->info("📊 バックテスト開始");
        $this->info("========================================");
        $this->info("閾値: {$threshold}%");
        $this->info("ルックバック期間: {$lookbackPeriod}本");
        $this->info("固定損切り: {$stopLossPercent}%");
        $this->info("トレーリングオフセット: {$trailingOffset}%");
        $this->info("最大ポジション: {$maxPositions}個");
        $this->info("スプレッド制限: {$maxSpreadPercent}%");
        $this->info("========================================\n");

        // 履歴データを取得
        $priceData = PriceHistory::where('symbol', 'XRP/JPY')
            ->orderBy('recorded_at', 'asc')
            ->get();

        if ($priceData->count() < $lookbackPeriod) {
            $this->error("データが不足しています。最低{$lookbackPeriod}件必要ですが、{$priceData->count()}件しかありません。");
            return 1;
        }

        $this->info("総データ数: {$priceData->count()}件\n");

        // バックテストループ
        for ($i = $lookbackPeriod; $i < $priceData->count(); $i++) {
            $currentPrice = $priceData[$i]->price;
            $currentTime = $priceData[$i]->recorded_at;

            // 直近の価格データ
            $recentPrices = $priceData->slice($i - $lookbackPeriod, $lookbackPeriod)
                ->pluck('price')
                ->toArray();

            // 1. 固定損切りチェック
            $this->checkStopLoss($currentPrice, $stopLossPercent, $currentTime);

            // 2. トレーリングストップ更新とチェック
            $this->updateTrailingStop($recentPrices, $trailingOffset);
            $this->checkTrailingStop($currentPrice, $currentTime);

            // 3. ブレイクアウト判定
            $recentHigh = max($recentPrices);
            $recentLow = min($recentPrices);
            $thresholdMultiplier = $threshold / 100;

            $isHighBreakout = $currentPrice > $recentHigh * (1 + $thresholdMultiplier);
            $isLowBreakdown = $currentPrice < $recentLow * (1 - $thresholdMultiplier);

            // スプレッド計算（簡易版: 0.05%と仮定）
            $estimatedSpread = $currentPrice * 0.0005;
            $maxSpreadValue = $currentPrice * ($maxSpreadPercent / 100);
            $spreadOk = $estimatedSpread <= $maxSpreadValue;

            // 4. シグナル処理
            if ($isHighBreakout && $spreadOk) {
                $this->processHighBreakout($currentPrice, $currentTime, $maxPositions, $stopLossPercent, $trailingOffset);
            } elseif ($isLowBreakdown && $spreadOk) {
                $this->processLowBreakdown($currentPrice, $currentTime, $maxPositions, $stopLossPercent, $trailingOffset);
            }
        }

        // 全ポジションクローズ（最終価格で）
        $finalPrice = $priceData->last()->price;
        $finalTime = $priceData->last()->recorded_at;
        foreach ($this->positions as &$position) {
            $this->closePosition($position, $finalPrice, $finalTime, 'backtest_end');
        }

        // 結果表示
        $this->displayResults();

        return 0;
    }

    private function checkStopLoss(float $currentPrice, float $stopLossPercent, $currentTime): void
    {
        foreach ($this->positions as &$position) {
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

        // クローズ済みポジションを削除
        $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');
    }

    private function updateTrailingStop(array $recentPrices, float $trailingOffset): void
    {
        $recentLow = min($recentPrices);
        $recentHigh = max($recentPrices);

        foreach ($this->positions as &$position) {
            if ($position['side'] === 'long') {
                $newTrailingStop = $recentLow * (1 - $trailingOffset / 100);
                if ($newTrailingStop > $position['trailing_stop']) {
                    $position['trailing_stop'] = $newTrailingStop;
                }
            } else {
                $newTrailingStop = $recentHigh * (1 + $trailingOffset / 100);
                if ($newTrailingStop < $position['trailing_stop']) {
                    $position['trailing_stop'] = $newTrailingStop;
                }
            }
        }
    }

    private function checkTrailingStop(float $currentPrice, $currentTime): void
    {
        foreach ($this->positions as &$position) {
            if ($position['side'] === 'long' && $currentPrice <= $position['trailing_stop']) {
                $this->closePosition($position, $currentPrice, $currentTime, 'trailing_stop');
            } elseif ($position['side'] === 'short' && $currentPrice >= $position['trailing_stop']) {
                $this->closePosition($position, $currentPrice, $currentTime, 'trailing_stop');
            }
        }

        $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');
    }

    private function processHighBreakout(float $price, $time, int $maxPositions, float $stopLossPercent, float $trailingOffset): void
    {
        $longCount = count(array_filter($this->positions, fn($p) => $p['side'] === 'long'));
        $shortCount = count(array_filter($this->positions, fn($p) => $p['side'] === 'short'));

        // ショート保有中なら全決済してロング
        if ($shortCount > 0) {
            foreach ($this->positions as &$position) {
                if ($position['side'] === 'short') {
                    $this->closePosition($position, $price, $time, 'reverse_breakout');
                }
            }
            $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');

            // 新規ロングエントリー
            $this->openPosition('long', $price, $time, $stopLossPercent, $trailingOffset);
        } elseif ($longCount < $maxPositions) {
            // ロング追加
            $this->openPosition('long', $price, $time, $stopLossPercent, $trailingOffset);
        }
    }

    private function processLowBreakdown(float $price, $time, int $maxPositions, float $stopLossPercent, float $trailingOffset): void
    {
        $longCount = count(array_filter($this->positions, fn($p) => $p['side'] === 'long'));
        $shortCount = count(array_filter($this->positions, fn($p) => $p['side'] === 'short'));

        // ロング保有中なら全決済してショート
        if ($longCount > 0) {
            foreach ($this->positions as &$position) {
                if ($position['side'] === 'long') {
                    $this->closePosition($position, $price, $time, 'reverse_breakout');
                }
            }
            $this->positions = array_filter($this->positions, fn($p) => $p['status'] === 'open');

            // 新規ショートエントリー
            $this->openPosition('short', $price, $time, $stopLossPercent, $trailingOffset);
        } elseif ($shortCount < $maxPositions) {
            // ショート追加
            $this->openPosition('short', $price, $time, $stopLossPercent, $trailingOffset);
        }
    }

    private function openPosition(string $side, float $price, $time, float $stopLossPercent, float $trailingOffset): void
    {
        $initialTrailingStop = $side === 'long'
            ? $price * (1 - 1.5 / 100)  // -1.5%
            : $price * (1 + 1.5 / 100); // +1.5%

        $this->positions[] = [
            'id' => $this->nextPositionId++,
            'side' => $side,
            'entry_price' => $price,
            'entry_time' => $time,
            'trailing_stop' => $initialTrailingStop,
            'quantity' => 0.01,
            'status' => 'open',
        ];
    }

    private function closePosition(array &$position, float $price, $time, string $reason): void
    {
        if ($position['status'] === 'closed') {
            return;
        }

        $profitLoss = $position['side'] === 'long'
            ? ($price - $position['entry_price']) * $position['quantity']
            : ($position['entry_price'] - $price) * $position['quantity'];

        $profitLossPercent = $position['side'] === 'long'
            ? (($price - $position['entry_price']) / $position['entry_price']) * 100
            : (($position['entry_price'] - $price) / $position['entry_price']) * 100;

        $position['exit_price'] = $price;
        $position['exit_time'] = $time;
        $position['profit_loss'] = $profitLoss;
        $position['profit_loss_percent'] = $profitLossPercent;
        $position['exit_reason'] = $reason;
        $position['status'] = 'closed';

        $this->closedTrades[] = $position;
    }

    private function displayResults(): void
    {
        $this->info("\n========================================");
        $this->info("📈 バックテスト結果");
        $this->info("========================================\n");

        if (empty($this->closedTrades)) {
            $this->warn("取引が発生しませんでした。");
            return;
        }

        $totalTrades = count($this->closedTrades);
        $totalProfitLoss = array_sum(array_column($this->closedTrades, 'profit_loss'));

        $winningTrades = array_filter($this->closedTrades, fn($t) => $t['profit_loss'] > 0);
        $losingTrades = array_filter($this->closedTrades, fn($t) => $t['profit_loss'] <= 0);

        $winCount = count($winningTrades);
        $loseCount = count($losingTrades);
        $winRate = $totalTrades > 0 ? ($winCount / $totalTrades) * 100 : 0;

        $avgWin = $winCount > 0 ? array_sum(array_column($winningTrades, 'profit_loss')) / $winCount : 0;
        $avgLoss = $loseCount > 0 ? array_sum(array_column($losingTrades, 'profit_loss')) / $loseCount : 0;

        $maxWin = !empty($winningTrades) ? max(array_column($winningTrades, 'profit_loss')) : 0;
        $maxLoss = !empty($losingTrades) ? min(array_column($losingTrades, 'profit_loss')) : 0;

        // 決済理由別集計
        $reasonCounts = [];
        foreach ($this->closedTrades as $trade) {
            $reason = $trade['exit_reason'];
            if (!isset($reasonCounts[$reason])) {
                $reasonCounts[$reason] = 0;
            }
            $reasonCounts[$reason]++;
        }

        $this->info("総取引数: {$totalTrades}回");
        $this->info("勝率: " . number_format($winRate, 2) . "%");
        $this->info("勝ちトレード: {$winCount}回");
        $this->info("負けトレード: {$loseCount}回");
        $this->info("");
        $this->info("総損益: " . number_format($totalProfitLoss, 4) . "円");
        $this->info("平均利益: " . number_format($avgWin, 4) . "円");
        $this->info("平均損失: " . number_format($avgLoss, 4) . "円");
        $this->info("最大利益: " . number_format($maxWin, 4) . "円");
        $this->info("最大損失: " . number_format($maxLoss, 4) . "円");
        $this->info("");

        $this->info("決済理由別:");
        foreach ($reasonCounts as $reason => $count) {
            $this->info("  {$reason}: {$count}回");
        }

        // 詳細トレード履歴（最初と最後の5件）
        $this->info("\n--- 最初の5件のトレード ---");
        foreach (array_slice($this->closedTrades, 0, 5) as $trade) {
            $this->displayTrade($trade);
        }

        if ($totalTrades > 10) {
            $this->info("\n--- 最後の5件のトレード ---");
            foreach (array_slice($this->closedTrades, -5) as $trade) {
                $this->displayTrade($trade);
            }
        } elseif ($totalTrades > 5) {
            $this->info("\n--- 残りのトレード ---");
            foreach (array_slice($this->closedTrades, 5) as $trade) {
                $this->displayTrade($trade);
            }
        }

        $this->info("\n========================================");
    }

    private function displayTrade(array $trade): void
    {
        $symbol = $trade['profit_loss'] > 0 ? '✅' : '❌';
        $this->line(sprintf(
            "%s [%s] %s: エントリー %.2f円 → 決済 %.2f円 | 損益: %.4f円 (%.2f%%) | 理由: %s",
            $symbol,
            $trade['id'],
            strtoupper($trade['side']),
            $trade['entry_price'],
            $trade['exit_price'],
            $trade['profit_loss'],
            $trade['profit_loss_percent'],
            $trade['exit_reason']
        ));
    }
}
