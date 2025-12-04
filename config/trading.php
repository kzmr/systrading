<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trading Mode
    |--------------------------------------------------------------------------
    |
    | トレーディングモード: 'paper' (仮想取引) または 'live' (実取引)
    | 本番環境での取引は慎重に設定してください
    |
    */
    'mode' => env('TRADING_MODE', 'paper'),

    /*
    |--------------------------------------------------------------------------
    | Exchange API Settings
    |--------------------------------------------------------------------------
    |
    | 取引所APIの設定
    | サポート取引所: 'gmo', 'binance', 'paper'
    |
    */
    'exchange' => [
        'name' => env('EXCHANGE_NAME', 'gmo'),
        'base_url' => env('EXCHANGE_BASE_URL', 'https://api.coin.z.com'),
        'api_key' => env('EXCHANGE_API_KEY', ''),
        'api_secret' => env('EXCHANGE_API_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Trading Parameters
    |--------------------------------------------------------------------------
    |
    | デフォルトの取引パラメータ
    |
    */
    'defaults' => [
        'trade_size' => env('TRADE_SIZE', 0.01),
        'max_positions' => env('MAX_POSITIONS', 3),
        'stop_loss_percent' => env('STOP_LOSS_PERCENT', 1.0),
        'take_profit_percent' => env('TAKE_PROFIT_PERCENT', 5.0),
        'max_spread' => env('MAX_SPREAD', 0.1), // 最大許容スプレッド（%）
        'trailing_stop_offset_percent' => env('TRAILING_STOP_OFFSET_PERCENT', 0.5), // トレーリングストップのオフセット（%）
        'initial_trailing_stop_percent' => env('INITIAL_TRAILING_STOP_PERCENT', 1.5), // トレーリングストップの初期値（%）
    ],
];
