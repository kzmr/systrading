<?php

namespace App\Console\Commands;

use App\Models\OrderBookSnapshot;
use App\Models\TradingSettings;
use App\Trading\Exchange\OrderBookFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 板情報のスナップショットを記録する
 *
 * 価格・出来高からは手数料を超える優位性が見つからなかったため、
 * 未検証の情報源として板の状態を蓄積する。
 *
 * 板情報は過去に遡って取得できないため、検証したくなった時点で
 * データが無いと何もできない。売買の稼働状況に関わらず収集する。
 */
class RecordOrderBook extends Command
{
    protected $signature = 'orderbook:record
        {--symbol=* : 記録する通貨ペア(省略時は trading_settings の全通貨ペア)}';

    protected $description = '板情報のスナップショットを記録（検証用データ収集）';

    public function handle(OrderBookFetcher $fetcher): int
    {
        $symbols = $this->resolveSymbols();

        if (empty($symbols)) {
            $this->warn('記録対象の通貨ペアがありません');
            return self::SUCCESS;
        }

        $recorded = 0;
        $recordedAt = now()->startOfMinute();

        foreach ($symbols as $symbol) {
            try {
                $book = $fetcher->fetch($symbol);
                $metrics = $this->summarize($book);

                if ($metrics === null) {
                    $this->error("{$symbol}: 板が空のためスキップ");
                    continue;
                }

                OrderBookSnapshot::updateOrCreate(
                    ['symbol' => $symbol, 'recorded_at' => $recordedAt],
                    $metrics
                );

                $recorded++;
                $this->line(sprintf(
                    '%s: mid=%.0f spread=%.4f%% imbalance(top20)=%+.3f',
                    $symbol,
                    $metrics['mid_price'],
                    $metrics['spread_percent'],
                    $metrics['imbalance_top20']
                ));
            } catch (\Exception $e) {
                // 1銘柄の失敗で他の銘柄の記録を止めない
                $this->error("{$symbol}: 記録失敗 - {$e->getMessage()}");
                Log::warning('Order book recording failed', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("板情報記録完了: {$recorded}/" . count($symbols) . '件');

        return self::SUCCESS;
    }

    /**
     * 板から分析用の集計値を計算する
     *
     * 全500段を保存すると容量が膨大になるため、以下に要約する。
     * - 最良気配とスプレッド
     * - 上位N段の厚み（板の見た目の厚さ）
     * - 仲値から一定範囲内の厚み（実際に約定しうる範囲の厚さ）
     * - 売買の偏り imbalance = (bid - ask) / (bid + ask)
     *
     * @return array<string, float>|null 板が空の場合は null
     */
    private function summarize(array $book): ?array
    {
        $bids = $book['bids'];
        $asks = $book['asks'];

        if (empty($bids) || empty($asks)) {
            return null;
        }

        $bestBid = $bids[0]['price'];
        $bestAsk = $asks[0]['price'];
        $mid = ($bestBid + $bestAsk) / 2;

        if ($mid <= 0) {
            return null;
        }

        $bidTop5 = $this->sumSize(array_slice($bids, 0, 5));
        $askTop5 = $this->sumSize(array_slice($asks, 0, 5));
        $bidTop20 = $this->sumSize(array_slice($bids, 0, 20));
        $askTop20 = $this->sumSize(array_slice($asks, 0, 20));

        $bid01 = $this->sumSizeWithin($bids, $mid, 0.1, 'bid');
        $ask01 = $this->sumSizeWithin($asks, $mid, 0.1, 'ask');
        $bid05 = $this->sumSizeWithin($bids, $mid, 0.5, 'bid');
        $ask05 = $this->sumSizeWithin($asks, $mid, 0.5, 'ask');

        return [
            'best_bid' => $bestBid,
            'best_ask' => $bestAsk,
            'mid_price' => $mid,
            'spread_percent' => ($bestAsk - $bestBid) / $mid * 100,
            'bid_size_top5' => $bidTop5,
            'ask_size_top5' => $askTop5,
            'bid_size_top20' => $bidTop20,
            'ask_size_top20' => $askTop20,
            'bid_size_within_01pct' => $bid01,
            'ask_size_within_01pct' => $ask01,
            'bid_size_within_05pct' => $bid05,
            'ask_size_within_05pct' => $ask05,
            'imbalance_top5' => $this->imbalance($bidTop5, $askTop5),
            'imbalance_top20' => $this->imbalance($bidTop20, $askTop20),
            'imbalance_within_05pct' => $this->imbalance($bid05, $ask05),
        ];
    }

    private function sumSize(array $rows): float
    {
        return array_sum(array_column($rows, 'size'));
    }

    /**
     * 仲値から指定%以内にある注文の数量を合計する
     */
    private function sumSizeWithin(array $rows, float $mid, float $percent, string $side): float
    {
        $limit = $side === 'bid'
            ? $mid * (1 - $percent / 100)
            : $mid * (1 + $percent / 100);

        $total = 0.0;
        foreach ($rows as $row) {
            $inRange = $side === 'bid'
                ? $row['price'] >= $limit
                : $row['price'] <= $limit;

            if (!$inRange) {
                continue;
            }

            $total += $row['size'];
        }

        return $total;
    }

    /**
     * 売買の偏り: 正なら買い板が厚い、負なら売り板が厚い
     */
    private function imbalance(float $bid, float $ask): float
    {
        $total = $bid + $ask;

        return $total > 0 ? ($bid - $ask) / $total : 0.0;
    }

    /**
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
