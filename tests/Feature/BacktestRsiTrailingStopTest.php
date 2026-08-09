<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * RSIバックテストのトレーリングストップ決済テスト
 *
 * 本番(OrderExecutor)はトレーリングストップで決済するが、
 * 従来のRSIバックテストは固定損切りしか持たず実運用を再現していなかった。
 * その差分を埋めた実装が正しく動くことを検証する。
 */
class BacktestRsiTrailingStopTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvPath = sys_get_temp_dir() . '/rsi_trailing_test_' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->csvPath)) {
            unlink($this->csvPath);
        }
        parent::tearDown();
    }

    /**
     * 価格系列からバックテスト用CSVを生成
     */
    private function writeCsv(array $prices): void
    {
        $handle = fopen($this->csvPath, 'w');
        fputcsv($handle, ['id', 'symbol', 'price', 'recorded_at']);

        $time = strtotime('2026-01-01 00:00:00');
        foreach ($prices as $i => $price) {
            fputcsv($handle, [
                $i + 1,
                'BTC/JPY',
                $price,
                date('Y-m-d H:i:s', $time + $i * 60),
            ]);
        }
        fclose($handle);
    }

    /**
     * 下落 → ロングエントリー → 上昇 → 反落 という系列を作る
     *
     * 下落でRSIが売られすぎになりロングエントリー、その後上昇して
     * トレーリングストップが切り上がり、反落で決済される
     */
    private function decliningThenRisingSeries(): array
    {
        $prices = [];

        // 100 → 85 まで下落（RSIを売られすぎにしてロングエントリーさせる）
        for ($p = 100; $p >= 85; $p--) {
            $prices[] = $p;
        }

        // 85 → 105 まで上昇（トレーリングストップが追従して切り上がる）
        for ($p = 86; $p <= 105; $p++) {
            $prices[] = $p;
        }

        // 105 → 100 へ反落（トレーリングストップに到達して決済）
        for ($p = 104; $p >= 100; $p--) {
            $prices[] = $p;
        }

        return $prices;
    }

    /**
     * トレーリング以外の決済経路を無効化した共通オプション
     *
     * RSI利確・タイムアウト・固定損切りを事実上無効にすることで、
     * トレーリングストップ単独の挙動を分離して検証する
     */
    private function trailingOnlyOptions(): array
    {
        return [
            '--csv' => $this->csvPath,
            '--symbol' => 'BTC/JPY',
            '--rsi-period' => 14,
            '--rsi-oversold' => 30,
            '--rsi-overbought' => 70,
            '--rsi-exit-long' => 999,   // RSI利確を無効化
            '--rsi-exit-short' => -999, // RSI利確を無効化
            '--max-hold' => 999999,     // タイムアウトを無効化
            '--stop-loss' => 99,        // 固定損切りを無効化
        ];
    }

    public function test_trailing_stop_closes_position_when_enabled(): void
    {
        $this->writeCsv($this->decliningThenRisingSeries());

        Artisan::call('trading:backtest-rsi', $this->trailingOnlyOptions() + [
            '--initial-trailing' => 2.0,
            '--trailing-offset' => 2.0,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('trailing_stop', $output);
    }

    public function test_no_trailing_exit_when_disabled(): void
    {
        $this->writeCsv($this->decliningThenRisingSeries());

        // トレーリング無効（デフォルト）では trailing_stop 決済は発生しない
        Artisan::call('trading:backtest-rsi', $this->trailingOnlyOptions());
        $output = Artisan::output();

        $this->assertStringNotContainsString('trailing_stop', $output);
    }

    public function test_trailing_stop_protects_profit_after_favorable_move(): void
    {
        $this->writeCsv($this->decliningThenRisingSeries());

        Artisan::call('trading:backtest-rsi', $this->trailingOnlyOptions() + [
            '--initial-trailing' => 2.0,
            '--trailing-offset' => 2.0,
        ]);
        $output = Artisan::output();

        // 上昇分をトレーリングが確保するため、収支はプラスで終わる
        $this->assertMatchesRegularExpression('/Total P&L: (?!-)/', $output);
    }

    public function test_settings_are_reported_in_output(): void
    {
        $this->writeCsv($this->decliningThenRisingSeries());

        Artisan::call('trading:backtest-rsi', $this->trailingOnlyOptions() + [
            '--initial-trailing' => 1.5,
            '--trailing-offset' => 0.8,
        ]);
        $output = Artisan::output();

        $this->assertStringContainsString('Initial Trailing Stop: 1.5%', $output);
        $this->assertStringContainsString('Trailing Offset: 0.8%', $output);
    }

    public function test_trailing_disabled_is_reported_as_off(): void
    {
        $this->writeCsv($this->decliningThenRisingSeries());

        Artisan::call('trading:backtest-rsi', $this->trailingOnlyOptions());
        $output = Artisan::output();

        $this->assertStringContainsString('Initial Trailing Stop: OFF', $output);
        $this->assertStringContainsString('Trailing Offset: OFF', $output);
    }
}
