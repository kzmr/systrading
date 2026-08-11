<?php

namespace App\Console\Commands;

use App\Models\PriceHistory;
use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 価格履歴を記録する
 *
 * 以前は OrderExecutor::execute() の副作用として記録していたため、
 * 全戦略を停止すると価格収集も止まり、検証用データが欠損していた。
 * バックテストやフォワードテストに使うデータは売買の稼働状況に
 * 依存すべきではないため、独立したコマンドとして切り出している。
 */
class RecordPriceHistory extends Command
{
    protected $signature = 'price:record
        {--symbol=* : 記録する通貨ペア(省略時は trading_settings の全通貨ペア)}';

    protected $description = '価格履歴を記録（戦略の稼働状況に依存しない）';

    public function handle(): int
    {
        $symbols = $this->resolveSymbols();

        if (empty($symbols)) {
            $this->warn('記録対象の通貨ペアがありません');
            return self::SUCCESS;
        }

        try {
            $client = app(ExchangeClient::class);
        } catch (\Exception $e) {
            $this->error('取引所クライアントの生成に失敗: ' . $e->getMessage());
            Log::error('Price recording failed to create exchange client', [
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $recorded = 0;

        foreach ($symbols as $symbol) {
            try {
                $price = $client->getCurrentPrice($symbol);

                // (symbol, recorded_at) にユニーク制約があるため、
                // 同一時刻の再実行では重複を作らず既存レコードを更新する
                PriceHistory::updateOrCreate(
                    [
                        'symbol' => $symbol,
                        'recorded_at' => now()->startOfMinute(),
                    ],
                    ['price' => $price]
                );

                $recorded++;
                $this->line("{$symbol}: {$price}");
            } catch (\Exception $e) {
                // 1銘柄の失敗で他の銘柄の記録を止めない
                $this->error("{$symbol}: 記録失敗 - {$e->getMessage()}");
                Log::warning('Price recording failed', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("価格記録完了: {$recorded}/" . count($symbols) . '件');

        return self::SUCCESS;
    }

    /**
     * 記録対象の通貨ペアを決定
     *
     * オプション未指定時は trading_settings に登録されている全通貨ペアを対象にする。
     * is_active は見ない（停止中の戦略でも、再開に備えてデータを貯めておくため）。
     *
     * @return array<int, string>
     */
    private function resolveSymbols(): array
    {
        $symbols = $this->option('symbol');

        if (!empty($symbols)) {
            return array_values(array_unique($symbols));
        }

        return TradingSettings::query()
            ->distinct()
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
    }
}
