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

/**
 * 複数戦略を同一通貨ペアで運用する際のポジション分離テスト
 *
 * 仕様:
 * - エントリー系処理（逆方向決済・ポジション数上限）は自戦略のポジションのみを対象にする
 * - 共通リスク管理（トレーリングストップ・決済価格計算）は全ポジションに適用するが、
 *   適用する%は各ポジションを作成した戦略の設定に従う
 */
class MultiStrategyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const SYMBOL = 'BTC/JPY';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createSettings(string $name, array $parameters): TradingSettings
    {
        return TradingSettings::create([
            'name' => $name,
            'symbol' => self::SYMBOL,
            'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
            'parameters' => $parameters,
            'is_active' => true,
        ]);
    }

    private function createMockExchangeClient(float $price): ExchangeClient
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
        $mock->shouldReceive('getCurrentPrice')->andReturn($price);

        foreach (['buy', 'sell'] as $side) {
            $mock->shouldReceive($side)->andReturn([
                'success' => true,
                'order_id' => $side . '-order-' . uniqid(),
                'symbol' => self::SYMBOL,
                'quantity' => 0.001,
                'price' => $price,
                'fee' => 0.0,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        foreach (['stopSell', 'stopBuy'] as $stopSide) {
            $mock->shouldReceive($stopSide)->andReturn([
                'success' => true,
                'order_id' => $stopSide . '-order-' . uniqid(),
                'symbol' => self::SYMBOL,
                'quantity' => 0.001,
                'triggerPrice' => $price,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        $mock->shouldReceive('cancelOrder')->andReturn(['success' => true]);
        $mock->shouldReceive('getOrderStatus')->andReturn(['status' => 'WAITING']);
        $mock->shouldReceive('getExecutionsByOrderId')->andReturn([]);

        return $mock;
    }

    private function createMockStrategy(int $settingsId, array $parameters, string $action): TradingStrategy
    {
        $mock = Mockery::mock(TradingStrategy::class);

        $mock->shouldReceive('getSettingsId')->andReturn($settingsId);
        $mock->shouldReceive('getName')->andReturn('MockStrategy#' . $settingsId);
        $mock->shouldReceive('getParameters')->andReturn($parameters);

        $mock->shouldReceive('analyze')->andReturn([
            'action' => $action,
            'quantity' => $parameters['trade_size'] ?? 0.001,
            'price' => null,
        ]);

        $mock->shouldReceive('shouldClosePosition')->andReturn(null);

        return $mock;
    }

    // ------------------------------------------------------------------
    // エントリー系: 自戦略のポジションのみを対象にする
    // ------------------------------------------------------------------

    public function test_buy_signal_does_not_close_other_strategy_short_position(): void
    {
        $owner = $this->createSettings('Owner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'stop_loss_percent' => 2.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);
        $runner = $this->createSettings('Runner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'max_spread' => 0.1,
            'stop_loss_percent' => 0.5,
            'trailing_stop_offset_percent' => 1.0,
            'initial_trailing_stop_percent' => 0.7,
        ]);

        $otherShort = Position::create([
            'symbol' => self::SYMBOL,
            'trading_settings_id' => $owner->id,
            'side' => 'short',
            'quantity' => 0.001,
            'entry_price' => 10000.0,
            'trailing_stop_price' => 10070.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $executor = new OrderExecutor(
            $this->createMockExchangeClient(10000.0),
            $this->createMockStrategy($runner->id, $runner->parameters, 'buy')
        );
        $executor->execute(self::SYMBOL);

        // 他戦略のショートは決済されない
        $this->assertEquals('open', $otherShort->fresh()->status);

        // 自戦略のロングは新規に建つ
        $ownLong = Position::where('trading_settings_id', $runner->id)
            ->where('side', 'long')
            ->where('status', 'open')
            ->first();
        $this->assertNotNull($ownLong);
    }

    public function test_short_signal_does_not_close_other_strategy_long_position(): void
    {
        $owner = $this->createSettings('Owner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'stop_loss_percent' => 2.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);
        $runner = $this->createSettings('Runner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'max_spread' => 0.1,
            'stop_loss_percent' => 0.5,
            'trailing_stop_offset_percent' => 1.0,
            'initial_trailing_stop_percent' => 0.7,
        ]);

        $otherLong = Position::create([
            'symbol' => self::SYMBOL,
            'trading_settings_id' => $owner->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10000.0,
            'trailing_stop_price' => 9930.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $executor = new OrderExecutor(
            $this->createMockExchangeClient(10000.0),
            $this->createMockStrategy($runner->id, $runner->parameters, 'short')
        );
        $executor->execute(self::SYMBOL);

        $this->assertEquals('open', $otherLong->fresh()->status);

        $ownShort = Position::where('trading_settings_id', $runner->id)
            ->where('side', 'short')
            ->where('status', 'open')
            ->first();
        $this->assertNotNull($ownShort);
    }

    public function test_max_positions_counts_only_own_strategy_positions(): void
    {
        $owner = $this->createSettings('Owner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'stop_loss_percent' => 2.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);
        $runner = $this->createSettings('Runner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'max_spread' => 0.1,
            'stop_loss_percent' => 0.5,
            'trailing_stop_offset_percent' => 1.0,
            'initial_trailing_stop_percent' => 0.7,
        ]);

        // 他戦略が上限ぶんのロングを保有している状態
        Position::create([
            'symbol' => self::SYMBOL,
            'trading_settings_id' => $owner->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10000.0,
            'trailing_stop_price' => 9930.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $executor = new OrderExecutor(
            $this->createMockExchangeClient(10000.0),
            $this->createMockStrategy($runner->id, $runner->parameters, 'buy')
        );
        $executor->execute(self::SYMBOL);

        // 他戦略の枠に阻まれず、自戦略のロングが建つ
        $ownLongCount = Position::where('trading_settings_id', $runner->id)
            ->where('side', 'long')
            ->where('status', 'open')
            ->count();
        $this->assertEquals(1, $ownLongCount);
    }

    // ------------------------------------------------------------------
    // 共通リスク管理: 全ポジション対象、ただし%は所有戦略のもの
    // ------------------------------------------------------------------

    public function test_trailing_stop_uses_owning_strategy_offset(): void
    {
        // 所有戦略のオフセットは 1.0%、実行中戦略は 0.5%
        $owner = $this->createSettings('Owner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'stop_loss_percent' => 2.0,
            'trailing_stop_offset_percent' => 1.0,
            'initial_trailing_stop_percent' => 0.7,
        ]);
        $runner = $this->createSettings('Runner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'max_spread' => 0.1,
            'stop_loss_percent' => 2.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);

        $position = Position::create([
            'symbol' => self::SYMBOL,
            'trading_settings_id' => $owner->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10000.0,
            'trailing_stop_price' => 9800.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $executor = new OrderExecutor(
            $this->createMockExchangeClient(10000.0),
            $this->createMockStrategy($runner->id, $runner->parameters, 'hold')
        );
        $executor->execute(self::SYMBOL);

        // 所有戦略の 1.0% が使われる: 10000 * (1 - 0.01) = 9900
        // 実行中戦略の 0.5% なら 9950 になってしまう
        $this->assertEqualsWithDelta(9900.0, $position->fresh()->trailing_stop_price, 0.01);
    }

    public function test_exit_order_price_uses_owning_strategy_stop_loss(): void
    {
        // 所有戦略の損切りは 2.0%（決済価格 9800）
        // 実行中戦略の損切りは 0.5%（決済価格 9950）
        // トレーリングは両者とも 0.5% だが、含み損中なので損切り側が保護的になる
        $owner = $this->createSettings('Owner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'stop_loss_percent' => 2.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);
        $runner = $this->createSettings('Runner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'max_spread' => 0.1,
            'stop_loss_percent' => 0.5,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);

        $position = Position::create([
            'symbol' => self::SYMBOL,
            'trading_settings_id' => $owner->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10000.0,
            'trailing_stop_price' => 9700.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $executor = new OrderExecutor(
            $this->createMockExchangeClient(9800.0),
            $this->createMockStrategy($runner->id, $runner->parameters, 'hold')
        );
        $executor->execute(self::SYMBOL);

        // トレーリング更新後: 9800 * (1 - 0.005) = 9751
        // 損切り(所有戦略 2.0%): 10000 * (1 - 0.02) = 9800
        // より保護的な max(9751, 9800) = 9800 が決済指値になる
        // 実行中戦略の 0.5% を誤用すると 9950 になってしまう
        $this->assertEqualsWithDelta(9800.0, $position->fresh()->exit_order_price, 0.01);
    }

    public function test_trailing_stop_still_applies_to_other_strategy_positions(): void
    {
        // 共通リスク管理は「全ポジション対象」の仕様を維持していることを確認
        $owner = $this->createSettings('Owner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'stop_loss_percent' => 5.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);
        $runner = $this->createSettings('Runner Strategy', [
            'trade_size' => 0.001,
            'max_positions' => 1,
            'max_spread' => 0.1,
            'stop_loss_percent' => 5.0,
            'trailing_stop_offset_percent' => 0.5,
            'initial_trailing_stop_percent' => 0.7,
        ]);

        $position = Position::create([
            'symbol' => self::SYMBOL,
            'trading_settings_id' => $owner->id,
            'side' => 'long',
            'quantity' => 0.001,
            'entry_price' => 10000.0,
            'trailing_stop_price' => 9800.0,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        $executor = new OrderExecutor(
            $this->createMockExchangeClient(10200.0),
            $this->createMockStrategy($runner->id, $runner->parameters, 'hold')
        );
        $executor->execute(self::SYMBOL);

        // 別戦略のポジションでもトレーリングは追従する: 10200 * (1 - 0.005) = 10149
        $this->assertEqualsWithDelta(10149.0, $position->fresh()->trailing_stop_price, 0.01);
    }
}
