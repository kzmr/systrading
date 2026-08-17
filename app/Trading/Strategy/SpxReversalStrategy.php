<?php

namespace App\Trading\Strategy;

use App\Models\Position;
use App\Models\SpxSession;
use Illuminate\Support\Facades\Log;

/**
 * 米国株急落後のBTC反発戦略
 *
 * 米国市場でS&P500が大きく下げた日、引け後にBTCが反発する傾向を捉える。
 * 「価格から価格を当てる」のではなく、別の市場の情報を使う点がこれまでの
 * 戦略と異なる。
 *
 * ## 検証結果(BTC/JPY・4時間保有・往復手数料0.106%控除前)
 *
 * | 期間 | 該当 | 期待値 | t値 | 勝率 |
 * |---|---|---|---|---|
 * | 学習 2024-05〜2025-08 | 77件 | +0.2279% | 2.26 | 62.3% |
 * | 検証 2025-09〜2026-08 | 34件 | +0.2926% | 2.13 | 64.7% |
 *
 * 保有時間は2〜8時間、閾値は下位10〜25%の範囲で安定しており、
 * 単一の最適値に依存していない。
 *
 * ## 既知の弱点
 *
 * 年別に見ると利益が2024年に偏っている(2024年 +0.366% に対し
 * 2025年 +0.055%、2026年 +0.037%、2023年 -0.067%)。
 * 実運用は少額での検証が目的であり、収益を期待するものではない。
 *
 * ## パラメータ
 *
 * - spx_threshold_percent: この値以下の下落でエントリー（既定 -0.40）
 * - entry_window_minutes: セッション完了後、エントリーを許可する時間（既定 60）
 * - max_hold_minutes: 保有時間（既定 240 = 4時間）
 */
class SpxReversalStrategy extends TradingStrategy
{
    public function analyze(array $marketData): array
    {
        $params = $this->getParameters();
        $threshold = (float) ($params['spx_threshold_percent'] ?? -0.40);
        $windowMinutes = (int) ($params['entry_window_minutes'] ?? 60);
        $symbol = $marketData['symbol'];

        $hold = ['action' => 'hold', 'quantity' => 0, 'price' => null];

        $session = SpxSession::orderByDesc('session_date')->first();

        if (!$session) {
            return $hold;
        }

        // セッション完了後の一定時間内のみエントリーする。
        // これがないと4時間保有して決済した後、同じシグナルで再エントリーしてしまう。
        if (!$session->isWithinEntryWindow($windowMinutes)) {
            return $hold;
        }

        if ($session->session_move_percent > $threshold) {
            return $hold;
        }

        // このセッションで既にエントリー済みなら見送る
        if ($this->hasEnteredForSession($symbol, $session)) {
            return $hold;
        }

        Log::info('SPX REVERSAL - BUY SIGNAL', [
            'symbol' => $symbol,
            'session_date' => $session->session_date->toDateString(),
            'spx_move_percent' => $session->session_move_percent,
            'threshold' => $threshold,
            'minutes_since_close' => $session->last_bar_at->diffInMinutes(now()),
        ]);

        return [
            'action' => 'buy',
            'quantity' => $params['trade_size'] ?? 0.001,
            'price' => null, // 成行注文
        ];
    }

    /**
     * このセッションに対して既にエントリーしているか
     */
    private function hasEnteredForSession(string $symbol, SpxSession $session): bool
    {
        return Position::where('symbol', $symbol)
            ->where('trading_settings_id', $this->getSettingsId())
            ->where('opened_at', '>=', $session->last_bar_at)
            ->exists();
    }

    /**
     * 保有時間による決済判定
     *
     * 本戦略の出口は時間のみ。検証では2〜8時間で安定しており、
     * 24時間まで持つとマイナスに転じるため、伸ばしすぎないこと。
     */
    public function shouldClosePosition(Position $position, float $currentPrice, array $marketData = []): ?array
    {
        $maxHold = (int) ($this->getParameters()['max_hold_minutes'] ?? 240);
        $held = $position->opened_at->diffInMinutes(now());

        if ($held < $maxHold) {
            return null;
        }

        Log::info('SPX Reversal Exit - Timeout', [
            'position_id' => $position->id,
            'hold_minutes' => $held,
            'max_hold_minutes' => $maxHold,
        ]);

        return ['reason' => 'timeout', 'hold_minutes' => $held];
    }
}
