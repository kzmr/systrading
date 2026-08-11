<?php

namespace Tests\Feature;

use App\Models\OrderBookSnapshot;
use App\Models\TradingSettings;
use App\Trading\Exchange\OrderBookFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

/**
 * 板情報記録コマンドのテスト
 *
 * 板情報は過去に遡って取得できないため、収集が止まると
 * その期間は永久に検証できなくなる。確実に動くことを担保する。
 */
class RecordOrderBookTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 仲値10,000を中心に、買い板が厚い状態を作る
     */
    private function mockFetcher(array $book, array $failingSymbols = []): void
    {
        $mock = Mockery::mock(OrderBookFetcher::class);

        $mock->shouldReceive('fetch')
            ->with(Mockery::not(...$failingSymbols ?: ['__none__']))
            ->andReturn($book);

        foreach ($failingSymbols as $symbol) {
            $mock->shouldReceive('fetch')->with($symbol)
                ->andThrow(new \Exception('API error'));
        }

        $this->app->bind(OrderBookFetcher::class, fn() => $mock);
    }

    private function sampleBook(): array
    {
        return [
            // 買い板: 9,999 から下へ。合計 top5 = 15.0
            'bids' => [
                ['price' => 9999.0, 'size' => 5.0],
                ['price' => 9998.0, 'size' => 4.0],
                ['price' => 9997.0, 'size' => 3.0],
                ['price' => 9996.0, 'size' => 2.0],
                ['price' => 9995.0, 'size' => 1.0],
                ['price' => 9000.0, 'size' => 100.0],  // 仲値から10%下（範囲外)
            ],
            // 売り板: 10,001 から上へ。合計 top5 = 5.0
            'asks' => [
                ['price' => 10001.0, 'size' => 1.0],
                ['price' => 10002.0, 'size' => 1.0],
                ['price' => 10003.0, 'size' => 1.0],
                ['price' => 10004.0, 'size' => 1.0],
                ['price' => 10005.0, 'size' => 1.0],
                ['price' => 11000.0, 'size' => 100.0], // 仲値から10%上（範囲外)
            ],
        ];
    }

    public function test_records_snapshot_with_computed_metrics(): void
    {
        $this->mockFetcher($this->sampleBook());

        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();

        $snapshot = OrderBookSnapshot::where('symbol', 'BTC/JPY')->first();
        $this->assertNotNull($snapshot);

        $this->assertEquals(9999.0, $snapshot->best_bid);
        $this->assertEquals(10001.0, $snapshot->best_ask);
        $this->assertEquals(10000.0, $snapshot->mid_price);
        $this->assertEqualsWithDelta(0.02, $snapshot->spread_percent, 0.0001);
    }

    public function test_imbalance_is_positive_when_bids_are_thicker(): void
    {
        $this->mockFetcher($this->sampleBook());

        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();

        $snapshot = OrderBookSnapshot::first();

        // 買い top5 = 15.0、売り top5 = 5.0 → (15-5)/(15+5) = 0.5
        $this->assertEquals(15.0, $snapshot->bid_size_top5);
        $this->assertEquals(5.0, $snapshot->ask_size_top5);
        $this->assertEqualsWithDelta(0.5, $snapshot->imbalance_top5, 0.0001);
    }

    public function test_size_within_range_excludes_far_orders(): void
    {
        $this->mockFetcher($this->sampleBook());

        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();

        $snapshot = OrderBookSnapshot::first();

        // 仲値10,000 の 0.5% 以内 = 9,950〜10,050
        // 9,000 と 11,000 の大口(各100.0)は範囲外として除外される
        $this->assertEquals(15.0, $snapshot->bid_size_within_05pct);
        $this->assertEquals(5.0, $snapshot->ask_size_within_05pct);
    }

    public function test_records_all_symbols_from_trading_settings(): void
    {
        TradingSettings::create([
            'name' => 'Test BTC', 'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [], 'is_active' => false,
        ]);
        TradingSettings::create([
            'name' => 'Test ETH', 'symbol' => 'ETH/JPY',
            'strategy' => 'App\\Trading\\Strategy\\RSIContrarianStrategy',
            'parameters' => [], 'is_active' => false,
        ]);

        $this->mockFetcher($this->sampleBook());

        $this->artisan('orderbook:record')->assertSuccessful();

        $this->assertEquals(2, OrderBookSnapshot::count());
    }

    public function test_is_idempotent_within_same_minute(): void
    {
        $this->mockFetcher($this->sampleBook());

        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();
        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();

        $this->assertEquals(1, OrderBookSnapshot::count());
    }

    public function test_one_symbol_failure_does_not_stop_others(): void
    {
        $mock = Mockery::mock(OrderBookFetcher::class);
        $mock->shouldReceive('fetch')->with('BTC/JPY')->andThrow(new \Exception('API error'));
        $mock->shouldReceive('fetch')->with('ETH/JPY')->andReturn($this->sampleBook());
        $this->app->bind(OrderBookFetcher::class, fn() => $mock);

        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY', 'ETH/JPY']])
            ->assertSuccessful();

        $this->assertDatabaseMissing('order_book_snapshots', ['symbol' => 'BTC/JPY']);
        $this->assertDatabaseHas('order_book_snapshots', ['symbol' => 'ETH/JPY']);
    }

    public function test_empty_book_is_skipped(): void
    {
        $this->mockFetcher(['bids' => [], 'asks' => []]);

        $this->artisan('orderbook:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();

        $this->assertEquals(0, OrderBookSnapshot::count());
    }
}
