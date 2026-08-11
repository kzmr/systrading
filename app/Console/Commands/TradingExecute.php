<?php

namespace App\Console\Commands;

use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use App\Trading\Exchange\ExchangeClientFactory;
use App\Trading\Executor\OrderExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TradingExecute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trading:execute';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自動トレーディングを実行';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('トレーディング実行開始: ' . now());

        try {
            // 有効な設定を取得
            $settings = TradingSettings::where('is_active', true)->get();

            if ($settings->isEmpty()) {
                $this->warn('有効なトレーディング設定がありません');
                return 0;
            }

            // Exchange クライアントを選択 (Paper or Live)
            $exchangeClient = $this->getExchangeClient();

            foreach ($settings as $setting) {
                $this->info("処理中: {$setting->name} ({$setting->symbol})");

                try {
                    // ストラテジーインスタンスを作成
                    $strategyClass = $setting->strategy;

                    if (!class_exists($strategyClass)) {
                        $this->error("ストラテジークラスが見つかりません: {$strategyClass}");
                        continue;
                    }

                    $strategy = new $strategyClass($setting);

                    // Order Executor を作成して実行
                    $executor = new OrderExecutor($exchangeClient, $strategy);
                    $result = $executor->execute($setting->symbol);

                    if ($result['success']) {
                        $message = $result['message'] ?? 'OK';
                        $this->info("✓ {$result['action']}: {$message}");
                    } else {
                        $this->error("✗ エラー: {$result['message']}");
                    }
                } catch (\Exception $e) {
                    $this->error("設定 {$setting->name} の実行中にエラー: {$e->getMessage()}");
                    Log::error('Trading execution error', [
                        'setting_id' => $setting->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $this->info('トレーディング実行完了: ' . now());
            return 0;
        } catch (\Exception $e) {
            $this->error('致命的エラー: ' . $e->getMessage());
            Log::error('Trading command fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * 環境変数に基づいてExchangeClientを取得
     */
    private function getExchangeClient(): ExchangeClient
    {
        if (ExchangeClientFactory::isLiveMode()) {
            $this->warn('⚠ ライブトレーディングモードで実行中');
        } else {
            $this->info('📝 ペーパートレーディングモードで実行中');
        }

        return ExchangeClientFactory::make();
    }
}
