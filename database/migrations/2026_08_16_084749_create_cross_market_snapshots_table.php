<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 市場横断のスナップショットを記録するテーブル
 *
 * BTC/JPY 単体の価格からは手数料を超える優位性が見つからなかったため、
 * 「価格を当てる」のではなく「市場間の関係の歪み」を捉える方向を検証する。
 *
 * 中心となる指標は日本プレミアム:
 *   premium = BTC/JPY ÷ (BTC/USD × USD/JPY) - 1
 *
 * 予備調査(60日分・4時間足)では、プレミアムの振れ幅が 0.270% と
 * 往復手数料 0.106% の2.5倍あり、水準別の次リターンが単調に並んだ。
 * ただしサンプルが少なく t 値が 2 に届かないため、継続収集して検証する。
 *
 * 重要: 複数の外部APIを「ほぼ同時刻」に取得することが本質。
 * 時刻がずれると、その間の値動きが偽のプレミアムとして現れる
 * (日足で検証した際、時刻が最大3時間ずれて標準偏差2.3%の偽シグナルが出た)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cross_market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->unique();

            // 国内取引所 BTC/JPY
            $table->decimal('gmo_bid', 16, 4)->nullable();
            $table->decimal('gmo_ask', 16, 4)->nullable();
            $table->decimal('gmo_mid', 16, 4)->nullable();
            $table->decimal('bitflyer_mid', 16, 4)->nullable();
            $table->decimal('coincheck_mid', 16, 4)->nullable();
            $table->decimal('bitbank_mid', 16, 4)->nullable();

            // 海外 BTC/USD と為替
            $table->decimal('btc_usd', 16, 4)->nullable();
            $table->decimal('usd_jpy', 12, 6)->nullable();

            // 為替の鮮度（土日はFX市場が閉じるため、暗号資産との非対称が生じる）
            $table->integer('fx_age_seconds')->nullable();

            // 導出値（生データからいつでも再計算できるが、参照しやすさのため保持）
            $table->decimal('fair_value_jpy', 16, 4)->nullable();
            $table->decimal('premium_percent', 10, 6)->nullable();

            $table->timestamps();

            $table->index('recorded_at', 'cross_market_snapshots_recorded_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_market_snapshots');
    }
};
