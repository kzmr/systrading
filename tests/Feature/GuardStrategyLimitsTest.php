<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\TradingLog;
use App\Models\TradingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 撤退基準の自動執行テスト
 *
 * 実際に資金を投じる戦略の安全装置。誤って停止しないこと、
 * そして条件を満たしたら確実に停止することの両方を担保する。
 */
class GuardStrategyLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function createSettings(array $guardParams = [], bool $active = true): TradingSettings
    {
        return TradingSettings::create([
            'name' => 'テスト戦略',
            'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\SpxReversalStrategy',
            'parameters' => array_merge([
                'trade_size' => 0.001,
                'max_cumulative_loss' => 3000,
                'evaluation_trade_count' => 20,
            ], $guardParams),
            'is_active' => $active,
        ]);
    }

    /**
     * 手数料込みで指定した純損益になるポジションを作る
     */
    private function addTrades(TradingSettings $s, int $count, float $netEach): void
    {
        for ($i = 0; $i < $count; $i++) {
            Position::create([
                'symbol' => 'BTC/JPY',
                'trading_settings_id' => $s->id,
                'side' => 'long',
                'quantity' => 0.001,
                'entry_price' => 10_000_000,
                'exit_price' => 10_000_000,
                'entry_fee' => 5,
                'exit_fee' => 5,
                'profit_loss' => $netEach + 10,   // 手数料10を足して純損益を netEach にする
                'status' => 'closed',
                'opened_at' => now()->subHours($count - $i + 4),
                'closed_at' => now()->subHours($count - $i),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 基準A: 累計損失の上限
    // ------------------------------------------------------------------

    public function test_stops_when_cumulative_loss_reaches_limit(): void
    {
        $s = $this->createSettings();
        $this->addTrades($s, 10, -300);   // 純損益 -3,000円

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertFalse($s->fresh()->is_active);
    }

    public function test_keeps_running_just_below_loss_limit(): void
    {
        $s = $this->createSettings();
        $this->addTrades($s, 10, -299);   // 純損益 -2,990円

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertTrue($s->fresh()->is_active);
    }

    public function test_records_log_when_stopped(): void
    {
        $s = $this->createSettings();
        $this->addTrades($s, 10, -300);

        $this->artisan('strategy:guard')->assertSuccessful();

        $log = TradingLog::where('action', 'strategy_auto_stopped')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('累計損失', $log->message);
    }

    // ------------------------------------------------------------------
    // 基準B: 一定取引数での期待値評価
    // ------------------------------------------------------------------

    public function test_stops_when_expectancy_is_negative_at_evaluation_point(): void
    {
        $s = $this->createSettings();
        $this->addTrades($s, 20, -10);    // 20取引・平均-10円

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertFalse($s->fresh()->is_active);
    }

    public function test_continues_when_expectancy_is_positive_at_evaluation_point(): void
    {
        $s = $this->createSettings();
        $this->addTrades($s, 20, +15);    // 20取引・平均+15円

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertTrue($s->fresh()->is_active);
    }

    public function test_does_not_evaluate_before_reaching_trade_count(): void
    {
        // 19取引で平均マイナスでも、評価時点に達していなければ停止しない
        $s = $this->createSettings();
        $this->addTrades($s, 19, -10);

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertTrue($s->fresh()->is_active);
    }

    public function test_stops_when_expectancy_is_exactly_zero(): void
    {
        // 期待値0は「プラスでない」ため停止する
        $s = $this->createSettings();
        $this->addTrades($s, 20, 0);

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertFalse($s->fresh()->is_active);
    }

    // ------------------------------------------------------------------
    // 誤停止の防止
    // ------------------------------------------------------------------

    public function test_does_not_stop_strategy_without_trades(): void
    {
        $s = $this->createSettings();

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertTrue($s->fresh()->is_active);
    }

    public function test_ignores_strategies_without_guard_parameters(): void
    {
        $s = TradingSettings::create([
            'name' => 'ガード無し戦略', 'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => ['trade_size' => 0.001],
            'is_active' => true,
        ]);
        $this->addTrades($s, 30, -500);

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertTrue($s->fresh()->is_active, 'ガード未設定の戦略は対象外');
    }

    public function test_counts_only_own_positions(): void
    {
        $target = $this->createSettings();
        $other = TradingSettings::create([
            'name' => '別戦略', 'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [], 'is_active' => false,
        ]);

        $this->addTrades($other, 20, -500);   // 他戦略の大損失
        $this->addTrades($target, 5, +100);   // 自戦略は好調

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_dry_run_does_not_stop(): void
    {
        $s = $this->createSettings();
        $this->addTrades($s, 10, -300);

        $this->artisan('strategy:guard', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue($s->fresh()->is_active);
    }

    public function test_fees_are_included_in_judgement(): void
    {
        // 手数料前はプラスでも、手数料控除後にマイナスなら停止する
        $s = $this->createSettings(['evaluation_trade_count' => 5]);
        for ($i = 0; $i < 5; $i++) {
            Position::create([
                'symbol' => 'BTC/JPY',
                'trading_settings_id' => $s->id,
                'side' => 'long', 'quantity' => 0.001,
                'entry_price' => 10_000_000, 'exit_price' => 10_000_050,
                'entry_fee' => 50, 'exit_fee' => 50,
                'profit_loss' => 50,          // 手数料100を引くと -50
                'status' => 'closed',
                'opened_at' => now()->subHours(5), 'closed_at' => now()->subHours(1),
            ]);
        }

        $this->artisan('strategy:guard')->assertSuccessful();

        $this->assertFalse($s->fresh()->is_active);
    }
}
