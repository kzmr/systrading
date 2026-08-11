<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * RSIバックテストのトレンドフィルター・クールダウンのテスト
 *
 * 本番(RSIContrarianStrategy)には存在するがバックテストに無かった
 * 2つのエントリー制限を実装した。実運用と同じ条件で検証できることを担保する。
 */
class BacktestRsiFiltersTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvPath = sys_get_temp_dir() . '/rsi_filters_test_' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }
        parent::tearDown();
    }

    private function writeCsv(array $prices): void
    {
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, ['id', 'symbol', 'price', 'recorded_at']);

        $time = strtotime('2026-01-01 00:00:00');
        foreach ($prices as $i => $price) {
            fputcsv($handle, [$i + 1, 'BTC/JPY', $price, date('Y-m-d H:i:s', $time + $i * 60)]);
        }
        fclose($handle);
    }

    /**
     * 継続的に下落する系列
     *
     * RSIは売られすぎ(買いシグナル)になる一方、価格は移動平均を下回るため
     * トレンドは down と判定される。本番の「下落トレンド中は買わない」により
     * エントリーが見送られる状況を作る。
     */
    private function decliningSeries(int $bars = 200): array
    {
        $prices = [];
        $price = 300.0;
        for ($i = 0; $i < $bars; $i++) {
            $prices[] = $price;
            $price -= 1.0;
        }

        return $prices;
    }

    private function baseOptions(): array
    {
        return [
            '--csv' => $this->csvPath,
            '--symbol' => 'BTC/JPY',
            '--rsi-period' => 14,
            '--rsi-oversold' => 30,
            '--rsi-overbought' => 70,
            '--rsi-exit-long' => 50,
            '--rsi-exit-short' => 50,
            '--max-hold' => 999999,
            '--stop-loss' => 99,
        ];
    }

    // ------------------------------------------------------------------
    // トレンドフィルター
    // ------------------------------------------------------------------

    public function test_trend_filter_blocks_long_entry_in_downtrend(): void
    {
        $this->writeCsv($this->decliningSeries());

        Artisan::call('trading:backtest-rsi', $this->baseOptions() + [
            '--trend-ma-period' => 60,
            '--trend-threshold' => 0.3,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('Skipped by trend filter', $output);
        $this->assertStringContainsString('Total trades: 0', $output);
    }

    public function test_entry_happens_when_trend_filter_disabled(): void
    {
        $this->writeCsv($this->decliningSeries());

        // 同じ価格系列でもフィルター無効ならエントリーする
        Artisan::call('trading:backtest-rsi', $this->baseOptions());
        $output = Artisan::output();

        $this->assertStringNotContainsString('Skipped by trend filter', $output);
        $this->assertStringNotContainsString('Total trades: 0', $output);
    }

    public function test_trend_filter_setting_is_reported(): void
    {
        $this->writeCsv($this->decliningSeries(80));

        Artisan::call('trading:backtest-rsi', $this->baseOptions() + [
            '--trend-ma-period' => 60,
            '--trend-threshold' => 0.5,
        ]);

        $this->assertStringContainsString('Trend Filter: MA60 / 0.5%', Artisan::output());
    }

    public function test_trend_filter_reported_as_off_by_default(): void
    {
        $this->writeCsv($this->decliningSeries(80));

        Artisan::call('trading:backtest-rsi', $this->baseOptions());

        $this->assertStringContainsString('Trend Filter: OFF', Artisan::output());
    }

    // ------------------------------------------------------------------
    // クールダウン
    // ------------------------------------------------------------------

    public function test_cooldown_blocks_entry_after_losing_trade(): void
    {
        $this->writeCsv($this->decliningSeries());

        // 下落継続でロングを建てては負けるため、クールダウンが繰り返し発動する
        Artisan::call('trading:backtest-rsi', $this->baseOptions() + [
            '--initial-trailing' => 1.0,
            '--trailing-offset' => 1.0,
            '--cooldown' => 30,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('Skipped by cooldown', $output);
    }

    public function test_cooldown_reduces_trade_count(): void
    {
        $this->writeCsv($this->decliningSeries());

        $trailing = ['--initial-trailing' => 1.0, '--trailing-offset' => 1.0];

        Artisan::call('trading:backtest-rsi', $this->baseOptions() + $trailing);
        $withoutCooldown = $this->extractTradeCount(Artisan::output());

        Artisan::call('trading:backtest-rsi', $this->baseOptions() + $trailing + ['--cooldown' => 30]);
        $withCooldown = $this->extractTradeCount(Artisan::output());

        $this->assertLessThan($withoutCooldown, $withCooldown);
    }

    public function test_cooldown_setting_is_reported(): void
    {
        $this->writeCsv($this->decliningSeries(80));

        Artisan::call('trading:backtest-rsi', $this->baseOptions() + ['--cooldown' => 45]);

        $this->assertStringContainsString('Cooldown: 45 minutes', Artisan::output());
    }

    public function test_cooldown_reported_as_off_by_default(): void
    {
        $this->writeCsv($this->decliningSeries(80));

        Artisan::call('trading:backtest-rsi', $this->baseOptions());

        $this->assertStringContainsString('Cooldown: OFF', Artisan::output());
    }

    private function extractTradeCount(string $output): int
    {
        preg_match('/Total trades: (\d+)/', $output, $matches);

        return (int) ($matches[1] ?? 0);
    }
}
