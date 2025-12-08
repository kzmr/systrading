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
    | Trading Parameters (Deprecated)
    |--------------------------------------------------------------------------
    |
    | 注意: トレーディングパラメータはDBのtrading_settings.parametersで管理
    | このセクションは後方互換性のために残していますが、新規開発では使用しないでください
    |
    | DBで管理するパラメータ:
    |   - trade_size: 1回の取引サイズ
    |   - max_positions: 同一方向の最大ポジション数
    |   - stop_loss_percent: 固定損切り（%）
    |   - initial_trailing_stop_percent: 初期トレーリングストップ（%）
    |   - trailing_stop_offset_percent: トレーリングストップオフセット（%）
    |   - max_spread: 最大許容スプレッド（%）
    |   - lookback_period: 戦略固有パラメータ
    |   - breakout_threshold: 戦略固有パラメータ
    |
    */
];
