<?php

namespace Database\Seeders;

use App\Models\TradingSettings;
use Illuminate\Database\Seeder;

class TradingSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // GMOコイン用のサンプル設定
        TradingSettings::create([
            'name' => 'BTC移動平均戦略（GMOコイン）',
            'symbol' => 'BTC/JPY',
            'strategy' => 'App\\Trading\\Strategy\\SimpleMovingAverageStrategy',
            'parameters' => [
                'short_period' => 5,
                'long_period' => 20,
                'trade_size' => 0.01,
            ],
            'is_active' => false, // デフォルトは無効にしておく
        ]);

        TradingSettings::create([
            'name' => 'ETH移動平均戦略（GMOコイン）',
            'symbol' => 'ETH/JPY',
            'strategy' => 'App\\Trading\\Strategy\\SimpleMovingAverageStrategy',
            'parameters' => [
                'short_period' => 10,
                'long_period' => 30,
                'trade_size' => 0.1,
            ],
            'is_active' => false,
        ]);

        TradingSettings::create([
            'name' => 'XRP移動平均戦略（GMOコイン）',
            'symbol' => 'XRP/JPY',
            'strategy' => 'App\\Trading\\Strategy\\SimpleMovingAverageStrategy',
            'parameters' => [
                'short_period' => 7,
                'long_period' => 25,
                'trade_size' => 100,
            ],
            'is_active' => false,
        ]);
    }
}
