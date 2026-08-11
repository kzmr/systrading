<?php

namespace App\Trading\Exchange;

/**
 * 設定に基づいて ExchangeClient を生成する
 *
 * 複数のコマンドから同じ生成ロジックを使うため切り出している。
 */
class ExchangeClientFactory
{
    /**
     * 現在の設定に対応する ExchangeClient を生成
     *
     * @throws \Exception 未対応の取引所が指定された場合
     */
    public static function make(): ExchangeClient
    {
        $mode = config('trading.mode', 'paper');

        if ($mode !== 'live') {
            return new PaperTradingClient();
        }

        $exchangeName = config('trading.exchange.name', 'gmo');

        return match ($exchangeName) {
            'gmo' => new GMOCoinClient(),
            'binance' => new LiveTradingClient(),
            default => throw new \Exception("未対応の取引所: {$exchangeName}"),
        };
    }

    /**
     * ライブトレーディングモードかどうか
     */
    public static function isLiveMode(): bool
    {
        return config('trading.mode', 'paper') === 'live';
    }
}
