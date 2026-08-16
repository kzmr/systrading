<?php

namespace Tests\Feature;

use App\Models\SpxSession;
use App\Trading\Market\SpxSessionFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class RecordSpxSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockFetcher(?array $session): void
    {
        $mock = Mockery::mock(SpxSessionFetcher::class);
        $mock->shouldReceive('fetchLatestSession')->andReturn($session);
        $this->app->bind(SpxSessionFetcher::class, fn() => $mock);
    }

    public function test_records_completed_session(): void
    {
        $this->mockFetcher([
            'session_date' => '2026-08-14',
            'first_close' => 7800.0,
            'last_close' => 7750.0,
            'bar_count' => 7,
            'session_move_percent' => -0.641,
            'last_bar_at' => time() - 3600,
            'is_complete' => true,
        ]);

        $this->artisan('spx:record')->assertSuccessful();

        $s = SpxSession::first();
        $this->assertNotNull($s);
        $this->assertEqualsWithDelta(-0.641, $s->session_move_percent, 0.001);
        $this->assertTrue($s->is_complete);
    }

    public function test_updates_existing_session_as_it_progresses(): void
    {
        // 取引時間中に繰り返し記録され、完了時に is_complete が立つ
        $base = [
            'session_date' => '2026-08-14',
            'first_close' => 7800.0,
            'bar_count' => 4,
            'last_bar_at' => time() - 600,
        ];

        $this->mockFetcher($base + ['last_close' => 7790.0, 'session_move_percent' => -0.128, 'is_complete' => false]);
        $this->artisan('spx:record')->assertSuccessful();
        $this->assertFalse(SpxSession::first()->is_complete);

        Mockery::close();
        $this->mockFetcher($base + ['last_close' => 7750.0, 'session_move_percent' => -0.641, 'bar_count' => 7, 'is_complete' => true]);
        $this->artisan('spx:record')->assertSuccessful();

        $this->assertEquals(1, SpxSession::count(), 'セッションは日付ごとに1件');
        $s = SpxSession::first();
        $this->assertTrue($s->is_complete);
        $this->assertEqualsWithDelta(-0.641, $s->session_move_percent, 0.001);
    }

    public function test_skips_when_data_is_insufficient(): void
    {
        $this->mockFetcher(null);

        $this->artisan('spx:record')->assertSuccessful();

        $this->assertEquals(0, SpxSession::count());
    }

    public function test_entry_window_boundaries(): void
    {
        $s = SpxSession::create([
            'session_date' => '2026-08-14',
            'first_close' => 7800.0, 'last_close' => 7750.0, 'bar_count' => 7,
            'session_move_percent' => -0.641,
            'last_bar_at' => now()->subMinutes(30),
            'is_complete' => true,
        ]);

        $this->assertTrue($s->isWithinEntryWindow(60), '30分経過は60分以内');
        $this->assertFalse($s->isWithinEntryWindow(15), '30分経過は15分を超過');
    }

    public function test_incomplete_session_is_never_in_entry_window(): void
    {
        $s = SpxSession::create([
            'session_date' => '2026-08-14',
            'first_close' => 7800.0, 'last_close' => 7750.0, 'bar_count' => 4,
            'session_move_percent' => -0.641,
            'last_bar_at' => now()->subMinutes(5),
            'is_complete' => false,
        ]);

        $this->assertFalse($s->isWithinEntryWindow(60));
    }
}
