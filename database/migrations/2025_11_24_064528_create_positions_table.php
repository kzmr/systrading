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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->comment('通貨ペア');
            $table->string('side')->comment('long/short');
            $table->decimal('quantity', 16, 8)->comment('数量');
            $table->decimal('entry_price', 16, 8)->comment('エントリー価格');
            $table->decimal('exit_price', 16, 8)->nullable()->comment('エグジット価格');
            $table->string('status')->default('open')->comment('open/closed');
            $table->decimal('profit_loss', 16, 8)->nullable()->comment('損益');
            $table->timestamp('opened_at')->comment('オープン日時');
            $table->timestamp('closed_at')->nullable()->comment('クローズ日時');
            $table->timestamps();

            $table->index('symbol');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
