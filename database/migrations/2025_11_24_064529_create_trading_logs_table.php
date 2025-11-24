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
        Schema::create('trading_logs', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->comment('通貨ペア');
            $table->string('action')->comment('buy/sell/hold/error');
            $table->decimal('quantity', 16, 8)->nullable()->comment('数量');
            $table->decimal('price', 16, 8)->nullable()->comment('価格');
            $table->text('result')->nullable()->comment('実行結果JSON');
            $table->text('message')->nullable()->comment('メッセージ');
            $table->timestamp('executed_at')->comment('実行日時');
            $table->timestamps();

            $table->index('symbol');
            $table->index('action');
            $table->index('executed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_logs');
    }
};
