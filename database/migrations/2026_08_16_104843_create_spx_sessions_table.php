<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&P500 の各取引セッションを記録するテーブル
 *
 * 米国株が大きく下げた日の引け後、BTCが反発する傾向を捉える戦略で使う。
 * 検証結果(BTC/JPY・4時間保有):
 *   学習(2024-05〜2025-08): 77件 +0.2279% t=2.26
 *   検証(2025-09〜2026-08): 34件 +0.2926% t=2.13
 *
 * セッションの変動率は「その日の最初の時間足終値 → 最後の時間足終値」で計算する。
 * バックテストと同じ計算方法を本番でも使うため、時間足の終値を保持する。
 *
 * 米国市場は 13:30〜20:00 UTC(夏時間)/ 14:30〜21:00 UTC(冬時間)。
 * 時刻を固定値で持たず、実際のデータから判定する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spx_sessions', function (Blueprint $table) {
            $table->id();
            $table->date('session_date')->unique();

            $table->decimal('first_close', 14, 4);
            $table->decimal('last_close', 14, 4);
            $table->unsignedSmallInteger('bar_count');

            // (last_close / first_close - 1) * 100
            $table->decimal('session_move_percent', 10, 6);

            // 最後の時間足の時刻。エントリー可能な時間帯の判定に使う
            $table->timestamp('last_bar_at');

            // セッションが完了しているか(market close 後かどうか)
            $table->boolean('is_complete')->default(false);

            $table->timestamps();

            $table->index(['session_date', 'is_complete'], 'spx_sessions_date_complete_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spx_sessions');
    }
};
