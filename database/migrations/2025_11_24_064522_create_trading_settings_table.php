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
        Schema::create('trading_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('設定名');
            $table->string('symbol')->comment('通貨ペア（例: BTC/USDT）');
            $table->string('strategy')->comment('戦略クラス名');
            $table->json('parameters')->nullable()->comment('戦略パラメータ');
            $table->boolean('is_active')->default(true)->comment('有効/無効');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_settings');
    }
};
