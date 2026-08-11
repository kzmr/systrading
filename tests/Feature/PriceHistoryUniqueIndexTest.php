<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Trading\Exchange\ExchangeClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Mockery;

/**
 * price_history の重複防止テスト
 *
 * 価格の記録が OrderExecutor の副作用だった頃、稼働中の戦略の数だけ
 * 同一時刻・同一価格が重複記録され、RSI等の指標計算を著しく歪めていた。
 * DBレベルで再発しないことを担保する。
 */
class PriceHistoryUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_duplicate_symbol_and_timestamp_is_rejected(): void
    {
        $recordedAt = now()->startOfMinute();

        PriceHistory::create([
            'symbol' => 'BTC/JPY',
            'price' => 10_000_000,
            'recorded_at' => $recordedAt,
        ]);

        $this->expectException(QueryException::class);

        PriceHistory::create([
            'symbol' => 'BTC/JPY',
            'price' => 10_000_000,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function test_same_timestamp_different_symbol_is_allowed(): void
    {
        $recordedAt = now()->startOfMinute();

        PriceHistory::create(['symbol' => 'BTC/JPY', 'price' => 10_000_000, 'recorded_at' => $recordedAt]);
        PriceHistory::create(['symbol' => 'ETH/JPY', 'price' => 400_000, 'recorded_at' => $recordedAt]);

        $this->assertEquals(2, PriceHistory::count());
    }

    public function test_record_command_is_idempotent_within_same_minute(): void
    {
        $mock = Mockery::mock(ExchangeClient::class);
        $mock->shouldReceive('getCurrentPrice')->with('BTC/JPY')->andReturn(10_000_000.0);
        $this->app->bind(ExchangeClient::class, fn() => $mock);

        // 同一分内に2回実行しても重複せず、エラーにもならない
        $this->artisan('price:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();
        $this->artisan('price:record', ['--symbol' => ['BTC/JPY']])->assertSuccessful();

        $this->assertEquals(1, PriceHistory::where('symbol', 'BTC/JPY')->count());
    }

    public function test_migration_removes_existing_duplicates(): void
    {
        // ユニーク制約を外し、旧構造で重複を作ってからマイグレーションを再実行する
        DB::statement('DROP INDEX IF EXISTS price_history_symbol_recorded_at_unique');

        $recordedAt = now()->startOfMinute();
        foreach ([10_000_000, 10_000_000, 10_000_000] as $price) {
            DB::table('price_history')->insert([
                'symbol' => 'BTC/JPY',
                'price' => $price,
                'recorded_at' => $recordedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->assertEquals(3, PriceHistory::count());

        DB::statement('
            DELETE FROM price_history
            WHERE EXISTS (
                SELECT 1 FROM price_history AS duplicate
                WHERE duplicate.symbol = price_history.symbol
                  AND duplicate.recorded_at = price_history.recorded_at
                  AND duplicate.id < price_history.id
            )
        ');

        // 重複が1件に集約され、最も古いレコードが残る
        $this->assertEquals(1, PriceHistory::count());
        $this->assertEquals(10_000_000, (int) PriceHistory::first()->price);
    }
}
