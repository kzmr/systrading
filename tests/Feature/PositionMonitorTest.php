<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\TradingLog;
use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use App\Trading\Monitor\PositionMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class PositionMonitorTest extends TestCase
{
    use RefreshDatabase;

    private TradingSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = TradingSettings::create([
            'name' => 'BTC高値安値ブレイク戦略',
            'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 0.01,
                'max_positions' => 2,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 1.0,
                'stop_loss_percent' => 0.5,
            ],
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockExchangeClient(float $price): ExchangeClient
    {
        $mock = Mockery::mock(ExchangeClient::class);

        $mock->shouldReceive('getCurrentPrice')->andReturn($price);
        $mock->shouldReceive('getOrderStatus')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('getExecutionsByOrderId')->andReturn([]);
        $mock->shouldReceive('cancelOrder')->andReturn(['success' => true]);

        $mock->shouldReceive('stopSell')->andReturn([
            'success' => true,
            'order_id' => 'stop-sell-' . uniqid(),
        ]);

        $mock->shouldReceive('stopBuy')->andReturn([
            'success' => true,
            'order_id' => 'stop-buy-' . uniqid(),
        ]);

        $mock->shouldReceive('sell')->andReturn([
            'success' => true,
            'order_id' => 'sell-' . uniqid(),
            'price' => $price,
            'fee' => $price * 0.01 * 0.0005,
        ]);

        $mock->shouldReceive('buy')->andReturn([
            'success' => true,
            'order_id' => 'buy-' . uniqid(),
            'price' => $price,
            'fee' => $price * 0.01 * 0.0005,
        ]);

        return $mock;
    }

    private function createLongPosition(array $overrides = []): Position
    {
        return Position::create(array_merge([
            'symbol' => 'BTC/JPY',
            'trading_settings_id' => $this->settings->id,
            'side' => 'long',
            'quantity' => 0.01,
            'entry_price' => 11000000,
            'entry_fee' => 5.5,
            'trailing_stop_price' => 11000000 * (1 - 0.7 / 100), // 10923000
            'exit_order_id' => 'exit-order-123',
            'exit_order_price' => 11000000 * (1 - 0.7 / 100),
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ], $overrides));
    }

    private function createShortPosition(array $overrides = []): Position
    {
        return Position::create(array_merge([
            'symbol' => 'BTC/JPY',
            'trading_settings_id' => $this->settings->id,
            'side' => 'short',
            'quantity' => 0.01,
            'entry_price' => 11000000,
            'entry_fee' => 5.5,
            'trailing_stop_price' => 11000000 * (1 + 0.7 / 100), // 11077000
            'exit_order_id' => 'exit-order-456',
            'exit_order_price' => 11000000 * (1 + 0.7 / 100),
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ], $overrides));
    }

    // =========================================
    // monitorAll() テスト
    // =========================================

    public function test_monitor_all_returns_zero_when_no_positions(): void
    {
        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);

        $result = $monitor->monitorAll();

        $this->assertEquals(0, $result['monitored']);
        $this->assertEmpty($result['actions']);
    }

    public function test_monitor_all_processes_multiple_positions(): void
    {
        $this->createLongPosition();
        $this->createShortPosition(['exit_order_id' => 'exit-order-789', 'exit_order_price' => 11077000]);

        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);

        $result = $monitor->monitorAll();

        $this->assertEquals(2, $result['monitored']);
    }

    // =========================================
    // トレーリングストップ更新テスト
    // =========================================

    public function test_trailing_stop_updates_on_favorable_price_movement_long(): void
    {
        // エントリー11,000,000 → 現在11,200,000（上昇）
        $position = $this->createLongPosition();
        $currentPrice = 11200000;

        $mock = $this->createMockExchangeClient($currentPrice);
        // cancelOrder should be called to update the STOP order
        $mock->shouldReceive('cancelOrder')->with('exit-order-123')->andReturn(['success' => true]);

        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNotNull($result);
        $this->assertEquals('trailing_stop_updated', $result['action']);

        $position->refresh();
        // 新TS = 11,200,000 * (1 - 1.0%) = 11,088,000
        $this->assertEqualsWithDelta(11088000, $position->trailing_stop_price, 1);
        $this->assertNotNull($position->exit_order_id);
    }

    public function test_trailing_stop_does_not_move_backward_long(): void
    {
        // エントリー11,000,000, 現在TS=10,923,000 → 現在価格10,950,000（TSより少し上）
        $position = $this->createLongPosition();
        $currentPrice = 10950000;

        // 新TS = 10,950,000 * (1 - 1%) = 10,840,500 → 現在の10,923,000より低い → 更新しない
        $mock = $this->createMockExchangeClient($currentPrice);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNull($result);

        $position->refresh();
        // TSは変わらない
        $this->assertEqualsWithDelta(10923000, $position->trailing_stop_price, 1);
    }

    public function test_trailing_stop_updates_on_favorable_price_movement_short(): void
    {
        // エントリー11,000,000 → 現在10,800,000（下落＝ショートに有利）
        $position = $this->createShortPosition();
        $currentPrice = 10800000;

        $mock = $this->createMockExchangeClient($currentPrice);
        $mock->shouldReceive('cancelOrder')->with('exit-order-456')->andReturn(['success' => true]);

        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNotNull($result);
        $this->assertEquals('trailing_stop_updated', $result['action']);

        $position->refresh();
        // 新TS = 10,800,000 * (1 + 1.0%) = 10,908,000 → 現在の11,077,000より低い → 更新
        $this->assertEqualsWithDelta(10908000, $position->trailing_stop_price, 1);
    }

    public function test_trailing_stop_does_not_move_backward_short(): void
    {
        // ショートでTS=11,077,000, 現在11,050,000 → 新TS=11,160,500 → 11,077,000より高い → 更新しない
        $position = $this->createShortPosition();
        $currentPrice = 11050000;

        $mock = $this->createMockExchangeClient($currentPrice);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNull($result);

        $position->refresh();
        $this->assertEqualsWithDelta(11077000, $position->trailing_stop_price, 1);
    }

    public function test_trailing_stop_initialized_when_null_long(): void
    {
        $position = $this->createLongPosition([
            'trailing_stop_price' => null,
            'exit_order_id' => null,
            'exit_order_price' => null,
        ]);

        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', 11000000, $params);

        $this->assertNotNull($result);
        $this->assertEquals('trailing_stop_initialized', $result['action']);

        $position->refresh();
        // 初期TS = 11,000,000 * (1 - 0.7%) = 10,923,000
        $this->assertEqualsWithDelta(10923000, $position->trailing_stop_price, 1);
        $this->assertNotNull($position->exit_order_id);
    }

    public function test_trailing_stop_initialized_when_null_short(): void
    {
        $position = $this->createShortPosition([
            'trailing_stop_price' => null,
            'exit_order_id' => null,
            'exit_order_price' => null,
        ]);

        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', 11000000, $params);

        $this->assertNotNull($result);
        $this->assertEquals('trailing_stop_initialized', $result['action']);

        $position->refresh();
        // 初期TS = 11,000,000 * (1 + 0.7%) = 11,077,000
        $this->assertEqualsWithDelta(11077000, $position->trailing_stop_price, 1);
        $this->assertNotNull($position->exit_order_id);
    }

    public function test_exit_order_placed_when_missing(): void
    {
        $position = $this->createLongPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
        ]);

        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->updateTrailingStop($position, 'BTC/JPY', 11000000, $params);

        $this->assertNotNull($result);
        $this->assertEquals('exit_order_placed', $result['action']);

        $position->refresh();
        $this->assertNotNull($position->exit_order_id);
    }

    // =========================================
    // STOP注文約定チェックテスト
    // =========================================

    public function test_exit_order_executed_closes_position(): void
    {
        $position = $this->createLongPosition();

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn(10900000);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn([
            'status' => 'EXECUTED',
        ]);
        $mock->shouldReceive('getExecutionsByOrderId')->with('exit-order-123')->andReturn([
            ['price' => 10923000, 'size' => 0.01, 'fee' => 5.5],
        ]);

        $monitor = new PositionMonitor($mock);

        $result = $monitor->checkExitOrderExecution($position, 'BTC/JPY', 10900000);

        $this->assertNotNull($result);
        $this->assertEquals('exit_order_executed', $result['action']);

        $position->refresh();
        $this->assertEquals('closed', $position->status);
        $this->assertEqualsWithDelta(10923000, $position->exit_price, 1);
        // 損益: (10923000 - 11000000) * 0.01 = -770
        $this->assertEqualsWithDelta(-770, $position->profit_loss, 1);
    }

    public function test_canceled_exit_order_is_cleared(): void
    {
        $position = $this->createLongPosition();

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn(11000000);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn([
            'status' => 'CANCELED',
        ]);

        $monitor = new PositionMonitor($mock);

        $result = $monitor->checkExitOrderExecution($position, 'BTC/JPY', 11000000);

        $this->assertNull($result); // ポジションは閉じられない

        $position->refresh();
        $this->assertEquals('open', $position->status);
        $this->assertNull($position->exit_order_id);
        $this->assertNull($position->exit_order_price);
    }

    public function test_no_action_when_no_exit_order(): void
    {
        $position = $this->createLongPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
        ]);

        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);

        $result = $monitor->checkExitOrderExecution($position, 'BTC/JPY', 11000000);

        $this->assertNull($result);
    }

    // =========================================
    // 緊急決済テスト
    // =========================================

    public function test_emergency_exit_on_price_gap_down_long(): void
    {
        $position = $this->createLongPosition([
            'exit_order_price' => 10923000,
        ]);

        // 価格がSTOP価格より0.5%以上下: 10923000 * 0.995 = 10868535
        $gapPrice = 10860000;

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn($gapPrice);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('cancelOrder')->with('exit-order-123')->once()->andReturn(['success' => true]);
        $mock->shouldReceive('sell')->once()->andReturn([
            'success' => true,
            'order_id' => 'emergency-sell',
            'price' => $gapPrice,
            'fee' => 5.43,
        ]);

        $monitor = new PositionMonitor($mock);

        $result = $monitor->checkExitOrderExecution($position, 'BTC/JPY', $gapPrice);

        $this->assertNotNull($result);
        $this->assertEquals('emergency_exit', $result['action']);
        $this->assertEquals('price_gap_down', $result['reason']);

        $position->refresh();
        $this->assertEquals('closed', $position->status);
        $this->assertEqualsWithDelta($gapPrice, $position->exit_price, 1);
    }

    public function test_emergency_exit_on_price_gap_up_short(): void
    {
        $position = $this->createShortPosition([
            'exit_order_price' => 11077000,
        ]);

        // 価格がSTOP価格より0.5%以上上: 11077000 * 1.005 = 11132385
        $gapPrice = 11140000;

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn($gapPrice);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-456')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('cancelOrder')->with('exit-order-456')->once()->andReturn(['success' => true]);
        $mock->shouldReceive('buy')->once()->andReturn([
            'success' => true,
            'order_id' => 'emergency-buy',
            'price' => $gapPrice,
            'fee' => 5.57,
        ]);

        $monitor = new PositionMonitor($mock);

        $result = $monitor->checkExitOrderExecution($position, 'BTC/JPY', $gapPrice);

        $this->assertNotNull($result);
        $this->assertEquals('emergency_exit', $result['action']);
        $this->assertEquals('price_gap_up', $result['reason']);

        $position->refresh();
        $this->assertEquals('closed', $position->status);
    }

    public function test_no_emergency_exit_within_threshold(): void
    {
        $position = $this->createLongPosition([
            'exit_order_price' => 10923000,
        ]);

        // 0.5%以内の価格: 10923000 * 0.995 = 10868535 → 10870000は範囲内
        $normalPrice = 10870000;

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn($normalPrice);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn(['status' => 'WAITING']);

        $monitor = new PositionMonitor($mock);

        $result = $monitor->checkExitOrderExecution($position, 'BTC/JPY', $normalPrice);

        $this->assertNull($result);

        $position->refresh();
        $this->assertEquals('open', $position->status);
    }

    // =========================================
    // フォールバック決済テスト
    // =========================================

    public function test_fallback_exit_long_when_price_below_stop(): void
    {
        $position = $this->createLongPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
            'trailing_stop_price' => 10923000,
        ]);

        $currentPrice = 10900000; // TSの10923000以下

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('sell')->once()->andReturn([
            'success' => true,
            'order_id' => 'fallback-sell',
            'price' => $currentPrice,
            'fee' => 5.45,
        ]);

        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->checkTrailingStopFallback($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNotNull($result);
        $this->assertEquals('fallback_trailing_stop_sell', $result['action']);

        $position->refresh();
        $this->assertEquals('closed', $position->status);
        $this->assertEqualsWithDelta($currentPrice, $position->exit_price, 1);
    }

    public function test_fallback_exit_short_when_price_above_stop(): void
    {
        $position = $this->createShortPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
            'trailing_stop_price' => 11077000,
        ]);

        // stop_loss = 11000000 * (1 + 0.5%) = 11055000
        // min(11077000, 11055000) = 11055000 がexit price
        $currentPrice = 11060000; // exit priceの11055000以上

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('buy')->once()->andReturn([
            'success' => true,
            'order_id' => 'fallback-buy',
            'price' => $currentPrice,
            'fee' => 5.53,
        ]);

        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->checkTrailingStopFallback($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNotNull($result);
        $this->assertEquals('fallback_trailing_stop_buy', $result['action']);

        $position->refresh();
        $this->assertEquals('closed', $position->status);
    }

    public function test_no_fallback_when_exit_order_exists(): void
    {
        // STOP注文がある場合はフォールバックしない
        $position = $this->createLongPosition();

        $mock = $this->createMockExchangeClient(10900000);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->checkTrailingStopFallback($position, 'BTC/JPY', 10900000, $params);

        $this->assertNull($result);
        $position->refresh();
        $this->assertEquals('open', $position->status);
    }

    public function test_no_fallback_when_price_above_stop_long(): void
    {
        $position = $this->createLongPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
            'trailing_stop_price' => 10923000,
        ]);

        // stop_loss = 11000000 * (1 - 0.5%) = 10945000
        // max(10923000, 10945000) = 10945000 がexit price
        $currentPrice = 11000000; // exit priceの10945000より上 → 決済しない

        $mock = $this->createMockExchangeClient($currentPrice);
        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->checkTrailingStopFallback($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNull($result);
        $position->refresh();
        $this->assertEquals('open', $position->status);
    }

    // =========================================
    // calculateExitPrice テスト（間接的に検証）
    // =========================================

    public function test_exit_price_uses_stop_loss_when_more_protective_long(): void
    {
        // stop_loss_percent=0.5 → stop_loss_price = 11000000 * 0.995 = 10945000
        // trailing_stop_price = 10923000
        // max(10923000, 10945000) = 10945000
        $position = $this->createLongPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
            'trailing_stop_price' => 10923000,
        ]);

        $currentPrice = 10940000; // 10945000 (stop_loss) > currentPrice → フォールバック発動

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('sell')->once()->andReturn([
            'success' => true,
            'order_id' => 'sell-order',
            'price' => $currentPrice,
            'fee' => 5.47,
        ]);

        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $result = $monitor->checkTrailingStopFallback($position, 'BTC/JPY', $currentPrice, $params);

        $this->assertNotNull($result);
        $position->refresh();
        $this->assertEquals('closed', $position->status);
    }

    // =========================================
    // TradingLog記録テスト
    // =========================================

    public function test_trading_log_created_on_exit_order_execution(): void
    {
        $position = $this->createLongPosition();

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn(10900000);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn(['status' => 'EXECUTED']);
        $mock->shouldReceive('getExecutionsByOrderId')->with('exit-order-123')->andReturn([
            ['price' => 10923000, 'size' => 0.01, 'fee' => 5.5],
        ]);

        $monitor = new PositionMonitor($mock);
        $monitor->checkExitOrderExecution($position, 'BTC/JPY', 10900000);

        $log = TradingLog::where('symbol', 'BTC/JPY')
            ->where('action', 'limit_exit_sell')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContains('モニター', $log->message);
    }

    public function test_trading_log_created_on_emergency_exit(): void
    {
        $position = $this->createLongPosition(['exit_order_price' => 10923000]);

        $gapPrice = 10860000;

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andReturn($gapPrice);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('cancelOrder')->with('exit-order-123')->andReturn(['success' => true]);
        $mock->shouldReceive('sell')->andReturn([
            'success' => true,
            'order_id' => 'emergency-sell',
            'price' => $gapPrice,
            'fee' => 5.43,
        ]);

        $monitor = new PositionMonitor($mock);
        $monitor->checkExitOrderExecution($position, 'BTC/JPY', $gapPrice);

        $log = TradingLog::where('symbol', 'BTC/JPY')
            ->where('action', 'emergency_exit_sell')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContains('モニター', $log->message);
    }

    public function test_trading_log_created_on_fallback_exit(): void
    {
        $position = $this->createLongPosition([
            'exit_order_id' => null,
            'exit_order_price' => null,
            'trailing_stop_price' => 10923000,
        ]);

        $currentPrice = 10900000;

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('sell')->andReturn([
            'success' => true,
            'order_id' => 'fallback-sell',
            'price' => $currentPrice,
            'fee' => 5.45,
        ]);

        $monitor = new PositionMonitor($mock);
        $params = $this->settings->parameters;

        $monitor->checkTrailingStopFallback($position, 'BTC/JPY', $currentPrice, $params);

        $log = TradingLog::where('symbol', 'BTC/JPY')
            ->where('action', 'fallback_trailing_stop_sell')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContains('モニター', $log->message);
    }

    // =========================================
    // エッジケーステスト
    // =========================================

    public function test_skips_position_with_deleted_trading_settings(): void
    {
        $position = $this->createLongPosition();

        // ポジション作成後にTradingSettingsを削除
        TradingSettings::where('id', $position->trading_settings_id)->delete();

        $mock = $this->createMockExchangeClient(11000000);
        $monitor = new PositionMonitor($mock);

        $result = $monitor->monitorAll();

        // エラーなく処理されること（スキップされる）
        $this->assertEquals(1, $result['monitored']);
        $this->assertEmpty($result['actions']);
    }

    public function test_handles_api_error_gracefully(): void
    {
        $this->createLongPosition();

        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->andThrow(new \Exception('API timeout'));

        $monitor = new PositionMonitor($mock);

        $result = $monitor->monitorAll();

        $this->assertEquals(1, $result['monitored']);
        $this->assertCount(1, $result['actions']);
        $this->assertEquals('error', $result['actions'][0]['action']);
    }

    public function test_multiple_symbols_monitored_independently(): void
    {
        // BTC/JPYポジション
        $this->createLongPosition();

        // XRP/JPY用の設定
        $xrpSettings = TradingSettings::create([
            'name' => 'XRP高値安値ブレイク戦略',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        // XRP/JPYポジション
        Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $xrpSettings->id,
            'side' => 'long',
            'quantity' => 10,
            'entry_price' => 320,
            'entry_fee' => 1.6,
            'trailing_stop_price' => 317.76,
            'exit_order_id' => 'xrp-exit-order',
            'exit_order_price' => 317.76,
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ]);

        $mock = Mockery::mock(ExchangeClient::class);
        // BTCとXRPで異なる価格を返す
        $mock->shouldReceive('getCurrentPrice')->with('BTC/JPY')->andReturn(11000000);
        $mock->shouldReceive('getCurrentPrice')->with('XRP/JPY')->andReturn(320);
        $mock->shouldReceive('getOrderStatus')->andReturn(['status' => 'WAITING']);

        $monitor = new PositionMonitor($mock);

        $result = $monitor->monitorAll();

        $this->assertEquals(2, $result['monitored']);
    }

    /**
     * カスタムアサーション: 文字列が部分文字列を含むか
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
