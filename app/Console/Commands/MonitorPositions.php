<?php

namespace App\Console\Commands;

use App\Trading\Exchange\ExchangeClient;
use App\Trading\Exchange\GMOCoinClient;
use App\Trading\Exchange\LiveTradingClient;
use App\Trading\Exchange\PaperTradingClient;
use App\Trading\Monitor\PositionMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorPositions extends Command
{
    protected $signature = 'trading:monitor {--interval=15 : 監視間隔（秒）}';

    protected $description = 'オープンポジションを高頻度で監視（トレーリングストップ更新・STOP注文管理）';

    public function handle(): int
    {
        $interval = (int) $this->option('interval');

        if ($interval < 5 || $interval > 60) {
            $this->error('間隔は5〜60秒の範囲で指定してください');
            return 1;
        }

        $this->info("ポジションモニター開始 (間隔: {$interval}秒)");
        Log::info("Position monitor started", ['interval' => $interval]);

        $exchangeClient = $this->getExchangeClient();
        $monitor = new PositionMonitor($exchangeClient);
        $cycleCount = 0;
        $statusInterval = max(1, intval(60 / $interval)); // 約1分ごとにステータス出力

        while (true) {
            try {
                $result = $monitor->monitorAll();
                $cycleCount++;

                if ($result['monitored'] > 0) {
                    $actionCount = count($result['actions']);
                    $timestamp = now()->format('H:i:s');

                    if ($actionCount > 0) {
                        $this->info("[{$timestamp}] 監視中: {$result['monitored']}ポジション, アクション: {$actionCount}件");
                        foreach ($result['actions'] as $action) {
                            $this->line("  - {$action['action']}: {$action['symbol']} (ID:{$action['position_id']})");
                        }
                    } elseif ($cycleCount % $statusInterval === 0) {
                        $this->line("[{$timestamp}] 監視中: {$result['monitored']}ポジション - 変動なし");
                    }
                }
            } catch (\Exception $e) {
                $this->error("モニターエラー: {$e->getMessage()}");
                Log::error('Position monitor loop error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            sleep($interval);
        }

        return 0;
    }

    private function getExchangeClient(): ExchangeClient
    {
        $mode = config('trading.mode', 'paper');
        $exchangeName = config('trading.exchange.name', 'gmo');

        if ($mode === 'live') {
            $this->warn('ライブトレーディングモードで監視中');

            return match ($exchangeName) {
                'gmo' => new GMOCoinClient(),
                'binance' => new LiveTradingClient(),
                default => throw new \Exception("未対応の取引所: {$exchangeName}"),
            };
        }

        $this->info('ペーパートレーディングモードで監視中');
        return new PaperTradingClient();
    }
}
