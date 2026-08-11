<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * price_history の重複レコードを削除し、再発を防ぐユニーク制約を追加する
 *
 * 価格の記録は OrderExecutor::execute() の副作用として行われていたため、
 * 稼働中の戦略の数だけ同じ時刻・同じ価格が重複記録されていた。
 *
 * 重複はRSI等の指標計算を著しく歪める。重複ペアの差分は必ず0になるため、
 * 例えばRSI(80)は実質40分ぶんの値動きしか見ておらず、変動も打ち消される。
 * 実際に本番ログのRSIとの比較では平均13.6ポイント、最大67.2ポイントの
 * 乖離が生じており、93.4%のレコードで1.0以上ずれていた。
 *
 * 記録処理自体は price:record コマンドに分離済み（毎分1回のみ記録）。
 * 本マイグレーションは過去データの是正と、DBレベルでの再発防止を行う。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 同一 (symbol, recorded_at) は最小 id のみ残す
        // 既存の複合インデックス (symbol, recorded_at) が効くため EXISTS で絞る
        DB::statement('
            DELETE FROM price_history
            WHERE EXISTS (
                SELECT 1 FROM price_history AS duplicate
                WHERE duplicate.symbol = price_history.symbol
                  AND duplicate.recorded_at = price_history.recorded_at
                  AND duplicate.id < price_history.id
            )
        ');

        Schema::table('price_history', function (Blueprint $table) {
            $table->unique(['symbol', 'recorded_at'], 'price_history_symbol_recorded_at_unique');
        });
    }

    public function down(): void
    {
        Schema::table('price_history', function (Blueprint $table) {
            $table->dropUnique('price_history_symbol_recorded_at_unique');
        });
    }
};
