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
            $table->unsignedBigInteger('trading_settings_id')->nullable()->after('symbol');
            $table->foreign('trading_settings_id')->references('id')->on('trading_settings')->onDelete('set null');
            $table->index('trading_settings_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['trading_settings_id']);
            $table->dropIndex(['trading_settings_id']);
            $table->dropColumn('trading_settings_id');
        });
    }
};
