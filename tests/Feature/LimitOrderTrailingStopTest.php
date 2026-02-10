<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use App\Trading\Executor\OrderExecutor;
use App\Trading\Strategy\TradingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class LimitOrderTrailingStopTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockExchangeClient(float $price): ExchangeClient
    {
        $mock = Mockery::mock(ExchangeClient::class);

        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, $price),
            'high' => $price * 1.01,
            'low' => $price * 0.99,
        ]);

        $mock->shouldReceive('getSpread')->andReturn(0.01);
        $mock->shouldReceive('getCurrentPrice')->andReturn($price);

        $mock->shouldReceive('buy')->andReturn([
            'success' => true,
            'order_id' => 'buy-order-' . uniqid(),
            'symbol' => 'XRP/JPY',
            'quantity' => 10,
            'price' => $price,
            'fee' => $price * 10 * 0.0005,
            'timestamp' => now()->toIso8601String(),
        ]);

        $mock->shouldReceive('sell')->andReturn([
            'success' => true,
            'order_id' => 'sell-order-' . uniqid(),
            'symbol' => 'XRP/JPY',
            'quantity' => 10,
            'price' => $price,
            'fee' => $price * 10 * 0.0005,
            'timestamp' => now()->toIso8601String(),
        ]);

        // STOP注文用のモック
        $mock->shouldReceive('stopSell')->andReturn([
            'success' => true,
            'order_id' => 'stop-sell-order-' . uniqid(),
            'symbol' => 'XRP/JPY',
            'quantity' => 10,
            'triggerPrice' => $price * 0.993, // 0.7% below
            'timestamp' => now()->toIso8601String(),
        ]);

        $mock->shouldReceive('stopBuy')->andReturn([
            'success' => true,
            'order_id' => 'stop-buy-order-' . uniqid(),
            'symbol' => 'XRP/JPY',
            'quantity' => 10,
            'triggerPrice' => $price * 1.007, // 0.7% above
            'timestamp' => now()->toIso8601String(),
        ]);

        $mock->shouldReceive('cancelOrder')->andReturn([
            'success' => true,
        ]);

        $mock->shouldReceive('getOrderStatus')->andReturn([
            'status' => 'WAITING',
        ]);

        $mock->shouldReceive('getExecutionsByOrderId')->andReturn([]);

        return $mock;
    }

    private function createMockStrategy(int $settingsId, string $action = 'hold'): TradingStrategy
    {
        $mock = Mockery::mock(TradingStrategy::class);

        $mock->shouldReceive('getSettingsId')->andReturn($settingsId);
        $mock->shouldReceive('getName')->andReturn('Test Strategy');

        $mock->shouldReceive('getParameters')->andReturn([
            'trade_size' => 10,
            'max_positions' => 3,
            'max_spread' => 0.1,
            'initial_trailing_stop_percent' => 0.7,
            'trailing_stop_offset_percent' => 0.5,
            'stop_loss_percent' => 1.0,
        ]);

        $mock->shouldReceive('analyze')->andReturn([
            'action' => $action,
            'quantity' => 10,
            'price' => null,
        ]);

        return $mock;
    }

    /**
     * ポジションオープン後、逆指値（STOP）注文が発注される
     */
    public function test_stop_order_placed_after_position_open(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        $exchangeClient = $this->createMockExchangeClient(320.0);
        $strategy = $this->createMockStrategy($settings->id, 'buy');

        $executor = new OrderExecutor($exchangeClient, $strategy);
        $result = $executor->execute('XRP/JPY');

        $this->assertTrue($result['success']);

        $position = Position::where('symbol', 'XRP/JPY')
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($position);
        // 逆指値（STOP）注文が発注される
        $this->assertNotNull($position->exit_order_id);
        $this->assertNotNull($position->exit_order_price);

        // 初期トレーリングストップ: 320 * (1 - 0.7%) = 317.76
        // 損切り価格: 320 * (1 - 1.0%) = 316.8
        // ロングなのでmax(317.76, 316.8) = 317.76
        $this->assertEqualsWithDelta(317.76, $position->exit_order_price, 0.01);
    }

    /**
     * 価格上昇時に逆指値注文が更新される
     */
    public function test_stop_order_updated_when_trailing_stop_moves(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        // 既存のロングポジション（逆指値注文あり）
        $position = Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $settings->id,
            'side' => 'long',
            'quantity' => 10,
            'entry_price' => 320.0,
            'entry_fee' => 1.6,
            'trailing_stop_price' => 317.76,
            'exit_order_id' => 'old-order-123',
            'exit_order_price' => 317.76,
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ]);

        // 価格が上昇した場合のモック（330円）
        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, 330.0),
        ]);
        $mock->shouldReceive('getSpread')->andReturn(0.01);
        $mock->shouldReceive('getCurrentPrice')->andReturn(330.0);
        $mock->shouldReceive('buy')->andReturn(['success' => true, 'order_id' => 'new-buy', 'price' => 330.0, 'fee' => 0]);
        $mock->shouldReceive('sell')->andReturn(['success' => true, 'order_id' => 'new-sell-order', 'price' => 330.0, 'fee' => 0]);
        // 既存の注文はキャンセルされる
        $mock->shouldReceive('cancelOrder')->once()->with('old-order-123')->andReturn(['success' => true]);
        // 新しい逆指値注文が発注される
        $mock->shouldReceive('stopSell')->once()->andReturn([
            'success' => true,
            'order_id' => 'new-stop-sell-order',
            'triggerPrice' => 328.35,
        ]);
        $mock->shouldReceive('getOrderStatus')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('getExecutionsByOrderId')->andReturn([]);

        $strategy = $this->createMockStrategy($settings->id, 'hold');

        $executor = new OrderExecutor($mock, $strategy);
        $executor->execute('XRP/JPY');

        $position->refresh();

        // 新しいトレーリングストップ: 330 * (1 - 0.5%) = 328.35
        // 損切り価格: 320 * (1 - 1.0%) = 316.8
        // max(328.35, 316.8) = 328.35
        $this->assertEqualsWithDelta(328.35, $position->trailing_stop_price, 0.01);
        // 新しい逆指値注文が発注される
        $this->assertEquals('new-stop-sell-order', $position->exit_order_id);
        $this->assertEqualsWithDelta(328.35, $position->exit_order_price, 0.01);
    }

    public function test_position_closed_when_exit_order_executed(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        // 既存のロングポジション
        $position = Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $settings->id,
            'side' => 'long',
            'quantity' => 10,
            'entry_price' => 320.0,
            'entry_fee' => 1.6,
            'trailing_stop_price' => 317.76,
            'exit_order_id' => 'exit-order-123',
            'exit_order_price' => 317.76,
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ]);

        // 約定済みとして返すモック
        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, 317.0),
        ]);
        $mock->shouldReceive('getSpread')->andReturn(0.01);
        $mock->shouldReceive('getCurrentPrice')->andReturn(317.0);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn([
            'status' => 'EXECUTED',
            'order_id' => 'exit-order-123',
        ]);
        $mock->shouldReceive('getExecutionsByOrderId')->with('exit-order-123')->andReturn([
            [
                'price' => 317.76,
                'size' => 10,
                'fee' => -0.32, // Maker rebate
            ],
        ]);
        $mock->shouldReceive('buy')->andReturn(['success' => true, 'order_id' => 'x', 'price' => 317.0, 'fee' => 0]);
        $mock->shouldReceive('sell')->andReturn(['success' => true, 'order_id' => 'x', 'price' => 317.0, 'fee' => 0]);
        $mock->shouldReceive('cancelOrder')->andReturn(['success' => true]);

        $strategy = $this->createMockStrategy($settings->id, 'hold');

        $executor = new OrderExecutor($mock, $strategy);
        $executor->execute('XRP/JPY');

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertEqualsWithDelta(317.76, $position->exit_price, 0.01);
        $this->assertEqualsWithDelta(-0.32, $position->exit_fee, 0.01); // Maker rebate
        // 損益: (317.76 - 320) * 10 = -22.4
        $this->assertEqualsWithDelta(-22.4, $position->profit_loss, 0.1);
    }

    public function test_emergency_exit_on_price_gap(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        // 既存のロングポジション
        $position = Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $settings->id,
            'side' => 'long',
            'quantity' => 10,
            'entry_price' => 320.0,
            'entry_fee' => 1.6,
            'trailing_stop_price' => 317.76,
            'exit_order_id' => 'exit-order-123',
            'exit_order_price' => 317.76,
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ]);

        // 価格が大きくギャップダウン（指値より0.5%以上下）
        // 指値: 317.76, 0.5%下 = 316.17
        // 現在価格: 315.0 (指値より0.87%下)
        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, 315.0),
        ]);
        $mock->shouldReceive('getSpread')->andReturn(0.01);
        $mock->shouldReceive('getCurrentPrice')->andReturn(315.0);
        $mock->shouldReceive('getOrderStatus')->with('exit-order-123')->andReturn([
            'status' => 'WAITING',
            'order_id' => 'exit-order-123',
        ]);
        $mock->shouldReceive('cancelOrder')->with('exit-order-123')->once()->andReturn(['success' => true]);
        $mock->shouldReceive('sell')->once()->andReturn([
            'success' => true,
            'order_id' => 'emergency-sell',
            'price' => 315.0,
            'fee' => 1.575, // Taker fee
        ]);
        $mock->shouldReceive('buy')->andReturn(['success' => true, 'order_id' => 'x', 'price' => 315.0, 'fee' => 0]);
        $mock->shouldReceive('getExecutionsByOrderId')->andReturn([]);

        $strategy = $this->createMockStrategy($settings->id, 'hold');

        $executor = new OrderExecutor($mock, $strategy);
        $executor->execute('XRP/JPY');

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertEqualsWithDelta(315.0, $position->exit_price, 0.01);
        $this->assertNull($position->exit_order_id);
        // 損益: (315 - 320) * 10 = -50
        $this->assertEqualsWithDelta(-50, $position->profit_loss, 0.1);
    }

    public function test_exit_order_cancelled_on_reverse_breakout(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        // 既存のロングポジション
        $position = Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $settings->id,
            'side' => 'long',
            'quantity' => 10,
            'entry_price' => 320.0,
            'entry_fee' => 1.6,
            'trailing_stop_price' => 317.76,
            'exit_order_id' => 'exit-order-123',
            'exit_order_price' => 317.76,
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ]);

        // ショートシグナルでロングを閉じる
        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, 310.0),
        ]);
        $mock->shouldReceive('getSpread')->andReturn(0.01);
        $mock->shouldReceive('getCurrentPrice')->andReturn(310.0);
        $mock->shouldReceive('getOrderStatus')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('getExecutionsByOrderId')->andReturn([]);

        // ロングクローズ時に指値注文がキャンセルされることを確認
        $mock->shouldReceive('cancelOrder')->with('exit-order-123')->once()->andReturn(['success' => true]);

        $mock->shouldReceive('sell')->andReturn([
            'success' => true,
            'order_id' => 'sell-order-' . uniqid(),
            'price' => 310.0,
            'fee' => 1.55,
        ]);
        $mock->shouldReceive('buy')->andReturn([
            'success' => true,
            'order_id' => 'buy-order-' . uniqid(),
            'price' => 310.0,
            'fee' => 1.55,
        ]);

        $strategy = $this->createMockStrategy($settings->id, 'short');

        $executor = new OrderExecutor($mock, $strategy);
        $executor->execute('XRP/JPY');

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertNull($position->exit_order_id);
        $this->assertNull($position->exit_order_price);
    }

    /**
     * ショートポジションでも逆指値（STOP）注文が発注される
     */
    public function test_short_position_stop_order(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => [
                'trade_size' => 10,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
                'trailing_stop_offset_percent' => 0.5,
                'stop_loss_percent' => 1.0,
            ],
            'is_active' => true,
        ]);

        $exchangeClient = $this->createMockExchangeClient(320.0);
        $strategy = $this->createMockStrategy($settings->id, 'short');

        $executor = new OrderExecutor($exchangeClient, $strategy);
        $result = $executor->execute('XRP/JPY');

        $this->assertTrue($result['success']);

        $position = Position::where('symbol', 'XRP/JPY')
            ->where('side', 'short')
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($position);
        // 逆指値（STOP）注文が発注される
        $this->assertNotNull($position->exit_order_id);
        $this->assertNotNull($position->exit_order_price);

        // 初期トレーリングストップ: 320 * (1 + 0.7%) = 322.24
        // 損切り価格: 320 * (1 + 1.0%) = 323.2
        // ショートなのでmin(322.24, 323.2) = 322.24
        $this->assertEqualsWithDelta(322.24, $position->exit_order_price, 0.01);
    }
}
