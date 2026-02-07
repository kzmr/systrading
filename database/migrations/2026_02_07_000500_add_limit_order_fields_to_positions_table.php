<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            // 指値注文管理用フィールド
            $table->string('exit_order_id')->nullable()->after('trailing_stop_price')
                ->comment('決済指値注文のID');
            $table->decimal('exit_order_price', 16, 8)->nullable()->after('exit_order_id')
                ->comment('発注済み決済指値の価格');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['exit_order_id', 'exit_order_price']);
        });
    }
};
