<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Models\TradingSettings;
use App\Trading\Exchange\ExchangeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

/**
 * 価格履歴記録コマンドのテスト
 *
 * 以前は OrderExecutor::execute() の副作用として価格を記録していたため、
 * 全戦略を停止すると価格収集も止まっていた。
 * 稼働状況に依存せず記録できることを担保する。
 */
class RecordPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createSettings(string $symbol, bool $isActive): TradingSettings
    {
        return TradingSettings::create([
            'name' => "Test {$symbol}",
            'symbol' => $symbol,
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => ['trade_size' => 0.001],
            'is_active' => $isActive,
        ]);
    }

    /**
     * ExchangeClient をモックしてコンテナに差し替える
     *
     * @param array<string, float> $prices symbol => price
     */
    private function mockExchangeClient(array $prices, array $failingSymbols = []): void
    {
        $mock = Mockery::mock(ExchangeClient::class);

        foreach ($prices as $symbol => $price) {
            $mock->shouldReceive('getCurrentPrice')->with($symbol)->andReturn($price);
        }

        foreach ($failingSymbols as $symbol) {
            $mock->shouldReceive('getCurrentPrice')->with($symbol)
                ->andThrow(new \Exception('API error'));
        }

        $this->app->bind(ExchangeClient::class, fn() => $mock);
    }

    public function test_records_price_for_symbol_option(): void
    {
        $this->mockExchangeClient(['BTC/JPY' => 10_000_000.0]);

        $this->artisan('price:record', ['--symbol' => ['BTC/JPY']])
            ->assertSuccessful();

        $record = PriceHistory::where('symbol', 'BTC/JPY')->first();
        $this->assertNotNull($record);
        $this->assertEquals(10_000_000.0, (float) $record->price);
    }

    public function test_records_even_when_all_strategies_are_inactive(): void
    {
        // 全戦略が停止していても価格は記録される（本コマンドの存在意義）
        $this->createSettings('BTC/JPY', false);
        $this->mockExchangeClient(['BTC/JPY' => 10_100_000.0]);

        $this->artisan('price:record')->assertSuccessful();

        $this->assertDatabaseHas('price_history', ['symbol' => 'BTC/JPY']);
    }

    public function test_records_all_symbols_from_trading_settings(): void
    {
        $this->createSettings('BTC/JPY', true);
        $this->createSettings('ETH/JPY', false);
        $this->mockExchangeClient([
            'BTC/JPY' => 10_000_000.0,
            'ETH/JPY' => 400_000.0,
        ]);

        $this->artisan('price:record')->assertSuccessful();

        $this->assertDatabaseHas('price_history', ['symbol' => 'BTC/JPY']);
        $this->assertDatabaseHas('price_history', ['symbol' => 'ETH/JPY']);
    }

    public function test_duplicate_symbols_are_recorded_once(): void
    {
        // 同じ通貨ペアに複数戦略が登録されていても1回だけ記録する
        $this->createSettings('BTC/JPY', true);
        $this->createSettings('BTC/JPY', false);
        $this->mockExchangeClient(['BTC/JPY' => 10_000_000.0]);

        $this->artisan('price:record')->assertSuccessful();

        $this->assertEquals(1, PriceHistory::where('symbol', 'BTC/JPY')->count());
    }

    public function test_one_symbol_failure_does_not_stop_others(): void
    {
        $this->mockExchangeClient(
            ['ETH/JPY' => 400_000.0],
            ['BTC/JPY']
        );

        $this->artisan('price:record', ['--symbol' => ['BTC/JPY', 'ETH/JPY']])
            ->assertSuccessful();

        $this->assertDatabaseMissing('price_history', ['symbol' => 'BTC/JPY']);
        $this->assertDatabaseHas('price_history', ['symbol' => 'ETH/JPY']);
    }

    public function test_succeeds_when_no_symbols_configured(): void
    {
        $this->artisan('price:record')->assertSuccessful();

        $this->assertEquals(0, PriceHistory::count());
    }
}
