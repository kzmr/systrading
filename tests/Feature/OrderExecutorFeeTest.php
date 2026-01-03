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

class OrderExecutorFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockExchangeClient(float $price, float $fee): ExchangeClient
    {
        $mock = Mockery::mock(ExchangeClient::class);

        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, $price),
            'high' => $price * 1.01,
            'low' => $price * 0.99,
            'ask' => $price,
            'bid' => $price,
        ]);

        $mock->shouldReceive('getSpread')->andReturn(0.01);

        $mock->shouldReceive('buy')->andReturn([
            'success' => true,
            'order_id' => 'test-order-id',
            'symbol' => 'XRP/JPY',
            'quantity' => 1,
            'price' => $price,
            'fee' => $fee,
            'timestamp' => now()->toIso8601String(),
        ]);

        $mock->shouldReceive('sell')->andReturn([
            'success' => true,
            'order_id' => 'test-order-id',
            'symbol' => 'XRP/JPY',
            'quantity' => 1,
            'price' => $price,
            'fee' => $fee,
            'timestamp' => now()->toIso8601String(),
        ]);

        return $mock;
    }

    private function createMockStrategy(int $settingsId, string $action = 'hold'): TradingStrategy
    {
        $mock = Mockery::mock(TradingStrategy::class);

        $mock->shouldReceive('getSettingsId')->andReturn($settingsId);

        $mock->shouldReceive('getParameters')->andReturn([
            'trade_size' => 1,
            'max_positions' => 3,
            'max_spread' => 0.1,
            'initial_trailing_stop_percent' => 0.7,
            'stop_loss_percent' => 1.0,
        ]);

        $mock->shouldReceive('analyze')->andReturn([
            'action' => $action,
            'quantity' => 1,
            'price' => 320.0,
        ]);

        // shouldClosePosition is optional
        $mock->shouldReceive('shouldClosePosition')->andReturn(null);

        return $mock;
    }

    public function test_long_entry_saves_entry_fee(): void
    {
        // Create trading settings
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [
                'trade_size' => 1,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
            ],
            'is_active' => true,
        ]);

        $exchangeClient = $this->createMockExchangeClient(320.0, 0.16);
        $strategy = $this->createMockStrategy($settings->id, 'buy');

        $executor = new OrderExecutor($exchangeClient, $strategy);
        $result = $executor->execute('XRP/JPY');

        $this->assertTrue($result['success']);

        $position = Position::where('symbol', 'XRP/JPY')
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($position);
        $this->assertEquals(0.16, $position->entry_fee);
        $this->assertNull($position->exit_fee);
    }

    public function test_short_entry_saves_entry_fee(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [
                'trade_size' => 1,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
            ],
            'is_active' => true,
        ]);

        $exchangeClient = $this->createMockExchangeClient(320.0, 0.16);
        $strategy = $this->createMockStrategy($settings->id, 'short');

        $executor = new OrderExecutor($exchangeClient, $strategy);
        $result = $executor->execute('XRP/JPY');

        $this->assertTrue($result['success']);

        $position = Position::where('symbol', 'XRP/JPY')
            ->where('side', 'short')
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($position);
        $this->assertEquals(0.16, $position->entry_fee);
        $this->assertNull($position->exit_fee);
    }

    public function test_position_close_saves_exit_fee(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [
                'trade_size' => 1,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
            ],
            'is_active' => true,
        ]);

        // Create an existing long position
        $position = Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $settings->id,
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 310.0,
            'entry_fee' => 0.155,
            'trailing_stop_price' => 307.83,
            'status' => 'open',
            'opened_at' => now()->subHours(1),
        ]);

        // Mock short signal to close long and open short
        $exchangeClient = $this->createMockExchangeClient(320.0, 0.16);
        $strategy = $this->createMockStrategy($settings->id, 'short');

        $executor = new OrderExecutor($exchangeClient, $strategy);
        $executor->execute('XRP/JPY');

        // Check the closed long position
        $closedPosition = Position::find($position->id);
        $this->assertEquals('closed', $closedPosition->status);
        $this->assertEquals(0.16, $closedPosition->exit_fee);
        $this->assertEquals(0.155, $closedPosition->entry_fee);
    }

    public function test_net_profit_loss_calculated_correctly_with_fees(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [
                'trade_size' => 1,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
            ],
            'is_active' => true,
        ]);

        // Create a closed position with fees
        $position = Position::create([
            'symbol' => 'XRP/JPY',
            'trading_settings_id' => $settings->id,
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 310.0,
            'entry_fee' => 0.155,
            'exit_price' => 320.0,
            'exit_fee' => 0.16,
            'trailing_stop_price' => 307.83,
            'profit_loss' => 10.0,
            'status' => 'closed',
            'opened_at' => now()->subHours(1),
            'closed_at' => now(),
        ]);

        $this->assertEquals(0.315, $position->total_fee);
        $this->assertEquals(9.685, $position->net_profit_loss);
    }

    public function test_fee_is_zero_when_not_returned_by_exchange(): void
    {
        $settings = TradingSettings::create([
            'name' => 'Test Strategy',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [
                'trade_size' => 1,
                'max_positions' => 3,
                'max_spread' => 0.1,
                'initial_trailing_stop_percent' => 0.7,
            ],
            'is_active' => true,
        ]);

        // Mock exchange client that doesn't return fee
        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getMarketData')->andReturn([
            'prices' => array_fill(0, 100, 320.0),
            'high' => 323.2,
            'low' => 316.8,
            'ask' => 320.0,
            'bid' => 320.0,
        ]);
        $mock->shouldReceive('getSpread')->andReturn(0.01);
        $mock->shouldReceive('buy')->andReturn([
            'success' => true,
            'order_id' => 'test-order-id',
            'symbol' => 'XRP/JPY',
            'quantity' => 1,
            'price' => 320.0,
            // No 'fee' key
            'timestamp' => now()->toIso8601String(),
        ]);

        $strategy = $this->createMockStrategy($settings->id, 'buy');
        $executor = new OrderExecutor($mock, $strategy);
        $executor->execute('XRP/JPY');

        $position = Position::where('symbol', 'XRP/JPY')
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($position);
        $this->assertEquals(0, $position->entry_fee);
    }
}
