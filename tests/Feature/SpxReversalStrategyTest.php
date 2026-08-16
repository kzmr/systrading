<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\SpxSession;
use App\Models\TradingSettings;
use App\Trading\Strategy\SpxReversalStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 米国株急落後のBTC反発戦略のテスト
 *
 * 実際に資金を投じる戦略のため、エントリー条件が意図通りに
 * 絞り込まれること（特に重複エントリーしないこと）を重点的に担保する。
 */
class SpxReversalStrategyTest extends TestCase
{
    use RefreshDatabase;

    private TradingSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = TradingSettings::create([
            'name' => 'SPX反発戦略テスト',
            'symbol' => 'BTC/JPY',
            'strategy' => SpxReversalStrategy::class,
            'parameters' => [
                'spx_threshold_percent' => -0.40,
                'entry_window_minutes' => 60,
                'max_hold_minutes' => 240,
                'trade_size' => 0.001,
                'max_positions' => 1,
            ],
            'is_active' => true,
        ]);
    }

    private function strategy(): SpxReversalStrategy
    {
        return new SpxReversalStrategy($this->settings->fresh());
    }

    private function makeSession(float $movePercent, int $minutesSinceClose = 10, bool $complete = true): SpxSession
    {
        return SpxSession::create([
            'session_date' => now()->toDateString(),
            'first_close' => 7800.0,
            'last_close' => 7800.0 * (1 + $movePercent / 100),
            'bar_count' => 7,
            'session_move_percent' => $movePercent,
            'last_bar_at' => now()->subMinutes($minutesSinceClose),
            'is_complete' => $complete,
        ]);
    }

    private function marketData(): array
    {
        return ['symbol' => 'BTC/JPY', 'prices' => array_fill(0, 100, 10_000_000.0)];
    }

    // ------------------------------------------------------------------
    // エントリー条件
    // ------------------------------------------------------------------

    public function test_buys_when_spx_dropped_below_threshold(): void
    {
        $this->makeSession(-0.80);

        $signal = $this->strategy()->analyze($this->marketData());

        $this->assertEquals('buy', $signal['action']);
        $this->assertEquals(0.001, $signal['quantity']);
        $this->assertNull($signal['price'], '成行注文であること');
    }

    public function test_holds_when_spx_drop_is_too_small(): void
    {
        $this->makeSession(-0.20);

        $this->assertEquals('hold', $this->strategy()->analyze($this->marketData())['action']);
    }

    public function test_holds_when_spx_rose(): void
    {
        $this->makeSession(+0.90);

        $this->assertEquals('hold', $this->strategy()->analyze($this->marketData())['action']);
    }

    public function test_enters_exactly_at_threshold(): void
    {
        // 閾値ちょうどは含む（<= で判定）
        $this->makeSession(-0.40);

        $this->assertEquals('buy', $this->strategy()->analyze($this->marketData())['action']);
    }

    // ------------------------------------------------------------------
    // 時間帯の制御
    // ------------------------------------------------------------------

    public function test_holds_when_session_is_not_complete(): void
    {
        // 取引時間中は判定しない（引け値が確定していない）
        $this->makeSession(-0.80, 10, false);

        $this->assertEquals('hold', $this->strategy()->analyze($this->marketData())['action']);
    }

    public function test_holds_after_entry_window_has_passed(): void
    {
        // 4時間保有して決済した後に再エントリーしないための制御
        $this->makeSession(-0.80, 300);

        $this->assertEquals('hold', $this->strategy()->analyze($this->marketData())['action']);
    }

    public function test_holds_when_no_session_data(): void
    {
        $this->assertEquals('hold', $this->strategy()->analyze($this->marketData())['action']);
    }

    // ------------------------------------------------------------------
    // 重複エントリーの防止
    // ------------------------------------------------------------------

    public function test_does_not_enter_twice_for_same_session(): void
    {
        $session = $this->makeSession(-0.80);

        Position::create([
            'symbol' => 'BTC/JPY',
            'trading_settings_id' => $this->settings->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10_000_000,
            'status' => 'open',
            'opened_at' => $session->last_bar_at->copy()->addMinutes(5),
        ]);

        $this->assertEquals('hold', $this->strategy()->analyze($this->marketData())['action']);
    }

    public function test_ignores_positions_from_previous_sessions(): void
    {
        $session = $this->makeSession(-0.80);

        // 前のセッションのポジションは判定に影響しない
        Position::create([
            'symbol' => 'BTC/JPY',
            'trading_settings_id' => $this->settings->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10_000_000,
            'status' => 'closed',
            'opened_at' => $session->last_bar_at->copy()->subDay(),
            'closed_at' => $session->last_bar_at->copy()->subDay()->addHours(4),
        ]);

        $this->assertEquals('buy', $this->strategy()->analyze($this->marketData())['action']);
    }

    public function test_ignores_positions_from_other_strategies(): void
    {
        $other = TradingSettings::create([
            'name' => '別戦略', 'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [], 'is_active' => false,
        ]);
        $session = $this->makeSession(-0.80);

        Position::create([
            'symbol' => 'BTC/JPY',
            'trading_settings_id' => $other->id,
            'side' => 'long',
            'quantity' => 0.01,
            'entry_price' => 10_000_000,
            'status' => 'open',
            'opened_at' => $session->last_bar_at->copy()->addMinutes(5),
        ]);

        $this->assertEquals('buy', $this->strategy()->analyze($this->marketData())['action']);
    }

    // ------------------------------------------------------------------
    // 決済
    // ------------------------------------------------------------------

    public function test_holds_position_before_max_hold(): void
    {
        $position = new Position([
            'symbol' => 'BTC/JPY', 'side' => 'long', 'quantity' => 0.001,
            'entry_price' => 10_000_000,
        ]);
        $position->opened_at = Carbon::now()->subMinutes(120);

        $this->assertNull($this->strategy()->shouldClosePosition($position, 10_050_000));
    }

    public function test_closes_position_after_max_hold(): void
    {
        $position = new Position([
            'symbol' => 'BTC/JPY', 'side' => 'long', 'quantity' => 0.001,
            'entry_price' => 10_000_000,
        ]);
        $position->opened_at = Carbon::now()->subMinutes(241);

        $result = $this->strategy()->shouldClosePosition($position, 10_050_000);

        $this->assertNotNull($result);
        $this->assertEquals('timeout', $result['reason']);
    }

    public function test_close_is_time_based_not_price_based(): void
    {
        // 含み損でも保有時間内なら決済しない（出口は時間のみ）
        $position = new Position([
            'symbol' => 'BTC/JPY', 'side' => 'long', 'quantity' => 0.001,
            'entry_price' => 10_000_000,
        ]);
        $position->opened_at = Carbon::now()->subMinutes(60);

        $this->assertNull($this->strategy()->shouldClosePosition($position, 9_500_000));
    }
}
