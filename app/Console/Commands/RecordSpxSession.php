<?php

namespace App\Console\Commands;

use App\Models\SpxSession;
use App\Trading\Market\SpxSessionFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * S&P500 のセッション情報を記録する
 *
 * SpxReversalStrategy のシグナル源。売買判断のたびに外部APIを叩くと
 * 遅延と障害の影響を受けるため、収集と判断を分離している。
 */
class RecordSpxSession extends Command
{
    protected $signature = 'spx:record';

    protected $description = 'S&P500のセッション情報を記録（BTC反発戦略のシグナル源）';

    public function handle(SpxSessionFetcher $fetcher): int
    {
        try {
            $session = $fetcher->fetchLatestSession();
        } catch (\Exception $e) {
            $this->error('取得失敗: ' . $e->getMessage());
            Log::warning('SPX session recording failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        if ($session === null) {
            $this->line('セッションデータが不足しているためスキップ');

            return self::SUCCESS;
        }

        // session_date は date キャストにより 'Y-m-d H:i:s' で保存される。
        // 検索側も Carbon で渡さないと書式が一致せず、同じ日の2回目以降が
        // INSERT になってユニーク制約に衝突する。
        $record = SpxSession::updateOrCreate(
            ['session_date' => Carbon::parse($session['session_date'])->startOfDay()],
            [
                'first_close' => $session['first_close'],
                'last_close' => $session['last_close'],
                'bar_count' => $session['bar_count'],
                'session_move_percent' => $session['session_move_percent'],
                'last_bar_at' => date('Y-m-d H:i:s', $session['last_bar_at']),
                'is_complete' => $session['is_complete'],
            ]
        );

        $this->line(sprintf(
            '%s: %+.3f%% (%d本, %s)',
            $record->session_date->toDateString(),
            $record->session_move_percent,
            $record->bar_count,
            $record->is_complete ? '完了' : '取引中'
        ));

        return self::SUCCESS;
    }
}
