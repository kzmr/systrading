<?php

namespace App\Trading\Exchange;

/**
 * 取引所クライアントのインターフェース
 */
interface ExchangeClient
{
    /**
     * 市場データを取得
     *
     * @param string $symbol 通貨ペア（例: BTC/USDT）
     * @param int $limit データ数
     * @return array
     */
    public function getMarketData(string $symbol, int $limit = 100): array;

    /**
     * 買い注文を実行
     *
     * @param string $symbol 通貨ペア
     * @param float $quantity 数量
     * @param float|null $price 価格（nullの場合は成行）
     * @return array 注文結果
     */
    public function buy(string $symbol, float $quantity, ?float $price = null): array;

    /**
     * 売り注文を実行
     *
     * @param string $symbol 通貨ペア
     * @param float $quantity 数量
     * @param float|null $price 価格（nullの場合は成行）
     * @return array 注文結果
     */
    public function sell(string $symbol, float $quantity, ?float $price = null): array;

    /**
     * 残高を取得
     *
     * @return array
     */
    public function getBalance(): array;

    /**
     * オープンポジションを取得
     *
     * @return array
     */
    public function getOpenPositions(): array;

    /**
     * 現在のスプレッドを取得
     *
     * @param string $symbol 通貨ペア
     * @return float スプレッド（円）
     */
    public function getSpread(string $symbol): float;
}
