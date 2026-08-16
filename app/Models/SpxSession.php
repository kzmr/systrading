<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S&P500 の1取引セッション
 *
 * 米国市場での変動率を保持し、引け後のBTC反発戦略のシグナル源になる。
 */
class SpxSession extends Model
{
    protected $fillable = [
        'session_date',
        'first_close',
        'last_close',
        'bar_count',
        'session_move_percent',
        'last_bar_at',
        'is_complete',
    ];

    protected $casts = [
        'session_date' => 'date',
        'first_close' => 'float',
        'last_close' => 'float',
        'bar_count' => 'integer',
        'session_move_percent' => 'float',
        'last_bar_at' => 'datetime',
        'is_complete' => 'boolean',
    ];

    /**
     * エントリー可能な時間帯か
     *
     * セッション完了後、一定時間内に限る。
     * これがないと、4時間保有して決済した後に同じシグナルで
     * 再エントリーしてしまう。
     */
    public function isWithinEntryWindow(int $windowMinutes): bool
    {
        if (!$this->is_complete) {
            return false;
        }

        $elapsed = $this->last_bar_at->diffInMinutes(now());

        return $elapsed >= 0 && $elapsed <= $windowMinutes;
    }
}
