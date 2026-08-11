<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 板情報のスナップショットを記録するテーブル
 *
 * 価格・出来高からは手数料を超える優位性が見つからなかったため、
 * 未検証の情報源として板の状態を収集する。
 *
 * 板は500段返ってくるが全量を保存すると容量が膨大になるため、
 * 分析に必要な集計値のみを記録する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_book_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->index();
            $table->timestamp('recorded_at')->index();

            // 最良気配とスプレッド
            $table->decimal('best_bid', 16, 8);
            $table->decimal('best_ask', 16, 8);
            $table->decimal('mid_price', 16, 8);
            $table->decimal('spread_percent', 10, 6);

            // 板の厚み（上位N段の数量合計）
            $table->decimal('bid_size_top5', 20, 8);
            $table->decimal('ask_size_top5', 20, 8);
            $table->decimal('bid_size_top20', 20, 8);
            $table->decimal('ask_size_top20', 20, 8);

            // 仲値から一定範囲内の厚み（段数ではなく価格帯で見る）
            $table->decimal('bid_size_within_01pct', 20, 8);
            $table->decimal('ask_size_within_01pct', 20, 8);
            $table->decimal('bid_size_within_05pct', 20, 8);
            $table->decimal('ask_size_within_05pct', 20, 8);

            // 売買の偏り: (bid - ask) / (bid + ask)。正なら買い板が厚い
            $table->decimal('imbalance_top5', 10, 6);
            $table->decimal('imbalance_top20', 10, 6);
            $table->decimal('imbalance_within_05pct', 10, 6);

            $table->timestamps();

            $table->unique(['symbol', 'recorded_at'], 'order_book_snapshots_symbol_recorded_at_unique');
            $table->index(['symbol', 'recorded_at'], 'order_book_snapshots_symbol_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_book_snapshots');
    }
};
