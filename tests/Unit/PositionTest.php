<?php

namespace Tests\Unit;

use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_fee_returns_sum_of_entry_and_exit_fees(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => 0.05,
            'exit_price' => 110,
            'exit_fee' => 0.055,
            'status' => 'closed',
        ]);

        $this->assertEqualsWithDelta(0.105, $position->total_fee, 0.0001);
    }

    public function test_total_fee_handles_null_fees(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => null,
            'exit_fee' => null,
            'status' => 'open',
        ]);

        $this->assertEquals(0, $position->total_fee);
    }

    public function test_total_fee_handles_partial_null_fees(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => 0.05,
            'exit_fee' => null,
            'status' => 'open',
        ]);

        $this->assertEquals(0.05, $position->total_fee);
    }

    public function test_net_profit_loss_subtracts_fees_from_profit(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => 0.05,
            'exit_price' => 110,
            'exit_fee' => 0.055,
            'profit_loss' => 10,
            'status' => 'closed',
        ]);

        // net_profit_loss = profit_loss - total_fee = 10 - 0.105 = 9.895
        $this->assertEquals(9.895, $position->net_profit_loss);
    }

    public function test_net_profit_loss_with_loss(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => 0.05,
            'exit_price' => 95,
            'exit_fee' => 0.0475,
            'profit_loss' => -5,
            'status' => 'closed',
        ]);

        // net_profit_loss = profit_loss - total_fee = -5 - 0.0975 = -5.0975
        $this->assertEquals(-5.0975, $position->net_profit_loss);
    }

    public function test_net_profit_loss_handles_null_profit_loss(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => 0.05,
            'exit_fee' => null,
            'profit_loss' => null,
            'status' => 'open',
        ]);

        // net_profit_loss = 0 - 0.05 = -0.05
        $this->assertEquals(-0.05, $position->net_profit_loss);
    }

    public function test_net_profit_loss_with_zero_fees(): void
    {
        $position = new Position([
            'symbol' => 'XRP/JPY',
            'side' => 'long',
            'quantity' => 1,
            'entry_price' => 100,
            'entry_fee' => 0,
            'exit_price' => 110,
            'exit_fee' => 0,
            'profit_loss' => 10,
            'status' => 'closed',
        ]);

        // net_profit_loss = profit_loss - 0 = 10
        $this->assertEquals(10, $position->net_profit_loss);
    }
}
