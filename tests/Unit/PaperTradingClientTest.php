<?php

namespace Tests\Unit;

use App\Trading\Exchange\PaperTradingClient;
use Tests\TestCase;
use Mockery;

class PaperTradingClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_buy_returns_fee_in_response(): void
    {
        // Mock the real data client
        $mockRealClient = Mockery::mock(\App\Trading\Exchange\GMOCoinClient::class);
        $mockRealClient->shouldReceive('getMarketData')
            ->andReturn(['prices' => [100.0]]);

        $client = new PaperTradingClient($mockRealClient);

        $result = $client->buy('XRP/JPY', 1, 100.0);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('fee', $result);
        // Fee should be 0.05% of cost (quantity * price)
        // 1 * 100 * 0.0005 = 0.05
        $this->assertEquals(0.05, $result['fee']);
    }

    public function test_buy_fee_calculation_is_correct(): void
    {
        $mockRealClient = Mockery::mock(\App\Trading\Exchange\GMOCoinClient::class);
        $mockRealClient->shouldReceive('getMarketData')
            ->andReturn(['prices' => [320.0]]);

        $client = new PaperTradingClient($mockRealClient);

        $result = $client->buy('XRP/JPY', 10, 320.0);

        $this->assertTrue($result['success']);
        // Fee = 10 * 320 * 0.0005 = 1.6
        $this->assertEquals(1.6, $result['fee']);
    }

    public function test_sell_returns_fee_in_response(): void
    {
        $mockRealClient = Mockery::mock(\App\Trading\Exchange\GMOCoinClient::class);
        $mockRealClient->shouldReceive('getMarketData')
            ->andReturn(['prices' => [100.0]]);

        $client = new PaperTradingClient($mockRealClient);

        $result = $client->sell('XRP/JPY', 1, 100.0);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('fee', $result);
        // Fee should be 0.05% of revenue (quantity * price)
        // 1 * 100 * 0.0005 = 0.05
        $this->assertEquals(0.05, $result['fee']);
    }

    public function test_sell_fee_calculation_for_short_entry(): void
    {
        $mockRealClient = Mockery::mock(\App\Trading\Exchange\GMOCoinClient::class);
        $mockRealClient->shouldReceive('getMarketData')
            ->andReturn(['prices' => [14000000.0]]);

        $client = new PaperTradingClient($mockRealClient);

        // BTC short entry
        $result = $client->sell('BTC/JPY', 0.01, 14000000.0);

        $this->assertTrue($result['success']);
        // Fee = 0.01 * 14000000 * 0.0005 = 70
        $this->assertEquals(70.0, $result['fee']);
    }

    public function test_fee_calculation_with_high_volume_trade(): void
    {
        $mockRealClient = Mockery::mock(\App\Trading\Exchange\GMOCoinClient::class);
        $mockRealClient->shouldReceive('getMarketData')
            ->andReturn(['prices' => [320.0]]);

        $client = new PaperTradingClient($mockRealClient);

        // High volume trade with explicit price
        $result = $client->buy('XRP/JPY', 30, 320.0);

        $this->assertTrue($result['success']);
        $this->assertEquals(320.0, $result['price']);
        // Fee = 30 * 320 * 0.0005 = 4.8
        $this->assertEquals(4.8, $result['fee']);
    }
}
