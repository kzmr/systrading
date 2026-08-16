<?php

namespace Tests\Feature;

use App\Models\CrossMarketSnapshot;
use App\Trading\Market\CrossMarketFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

/**
 * 市場横断スナップショット記録のテスト
 *
 * 日本プレミアムは3つの外部データを組み合わせて計算するため、
 * 一部が欠けたときの挙動と、計算式の正しさを担保する。
 */
class RecordCrossMarketTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockFetcher(array $overrides = []): void
    {
        $base = [
            'gmo_bid' => 10_000_000.0,
            'gmo_ask' => 10_002_000.0,
            'gmo_mid' => 10_001_000.0,
            'bitflyer_mid' => 10_003_000.0,
            'coincheck_mid' => 10_005_000.0,
            'bitbank_mid' => 10_002_000.0,
            'btc_usd' => 62_500.0,
            'usd_jpy' => 160.0,
            'fx_age_seconds' => 30,
        ];

        $mock = Mockery::mock(CrossMarketFetcher::class);
        $mock->shouldReceive('fetch')->andReturn(array_merge($base, $overrides));
        $this->app->bind(CrossMarketFetcher::class, fn() => $mock);
    }

    public function test_calculates_premium_from_three_sources(): void
    {
        // 理論値 = 62,500 × 160 = 10,000,000
        // プレミアム = 10,001,000 / 10,000,000 - 1 = +0.01%
        $this->mockFetcher();

        $this->artisan('market:record')->assertSuccessful();

        $s = CrossMarketSnapshot::first();
        $this->assertNotNull($s);
        $this->assertEqualsWithDelta(10_000_000.0, $s->fair_value_jpy, 0.01);
        $this->assertEqualsWithDelta(0.01, $s->premium_percent, 0.0001);
    }

    public function test_premium_is_negative_when_domestic_price_is_cheaper(): void
    {
        $this->mockFetcher(['gmo_mid' => 9_950_000.0]);

        $this->artisan('market:record')->assertSuccessful();

        $this->assertEqualsWithDelta(-0.5, CrossMarketSnapshot::first()->premium_percent, 0.0001);
    }

    public function test_skips_premium_when_fx_is_missing(): void
    {
        // 為替が取れないとプレミアムは計算できないが、他の値は記録する
        $this->mockFetcher(['usd_jpy' => null]);

        $this->artisan('market:record')->assertSuccessful();

        $s = CrossMarketSnapshot::first();
        $this->assertNotNull($s);
        $this->assertNull($s->premium_percent);
        $this->assertEquals(10_001_000.0, $s->gmo_mid);
    }

    public function test_skips_premium_when_overseas_price_is_missing(): void
    {
        $this->mockFetcher(['btc_usd' => null]);

        $this->artisan('market:record')->assertSuccessful();

        $this->assertNull(CrossMarketSnapshot::first()->premium_percent);
    }

    public function test_domestic_spread_uses_widest_pair(): void
    {
        // GMO 10,001,000 / bitFlyer 10,003,000 / Coincheck 10,005,000 / bitbank 10,002,000
        // 最大差 = 10,005,000 / 10,001,000 - 1 = 0.03999%
        $this->mockFetcher();

        $this->artisan('market:record')->assertSuccessful();

        $this->assertEqualsWithDelta(0.03999, CrossMarketSnapshot::first()->domesticSpreadPercent(), 0.0001);
    }

    public function test_domestic_spread_is_null_with_single_exchange(): void
    {
        $this->mockFetcher([
            'bitflyer_mid' => null, 'coincheck_mid' => null, 'bitbank_mid' => null,
        ]);

        $this->artisan('market:record')->assertSuccessful();

        $this->assertNull(CrossMarketSnapshot::first()->domesticSpreadPercent());
    }

    public function test_detects_stale_fx_outside_market_hours(): void
    {
        // FX市場は土日に閉じる。暗号資産は動き続けるため区別が必要
        $this->mockFetcher(['fx_age_seconds' => 60 * 60 * 40]);

        $this->artisan('market:record')->assertSuccessful();

        $s = CrossMarketSnapshot::first();
        $this->assertFalse($s->hasFreshFx());
        // 鮮度が低くてもプレミアム自体は記録する（後で選別できるようにする）
        $this->assertNotNull($s->premium_percent);
    }

    public function test_is_idempotent_within_same_minute(): void
    {
        $this->mockFetcher();

        $this->artisan('market:record')->assertSuccessful();
        $this->artisan('market:record')->assertSuccessful();

        $this->assertEquals(1, CrossMarketSnapshot::count());
    }
}
