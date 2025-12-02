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
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->index(); // 通貨ペア（例: XRP/JPY）
            $table->decimal('price', 16, 8); // 価格（最大8桁の小数点）
            $table->timestamp('recorded_at')->index(); // 記録日時
            $table->timestamps();

            // 複合インデックス（通貨ペアと日時での検索を高速化）
            $table->index(['symbol', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
