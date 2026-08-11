<?php

namespace App\Trading\Exchange;

use GuzzleHttp\Client;

/**
 * 板情報を取得する
 *
 * GMOコインの public エンドポイントを使う（認証不要）。
 * 検証用のデータ収集専用であり、売買には関与しないため
 * ExchangeClient インターフェースからは独立させている。
 */
class OrderBookFetcher
{
    private Client $httpClient;
    private string $baseUrl;

    public function __construct(?Client $httpClient = null, ?string $baseUrl = null)
    {
        $this->httpClient = $httpClient ?? new Client(['timeout' => 10]);
        $this->baseUrl = $baseUrl ?? 'https://api.coin.z.com/public';
    }

    /**
     * 板情報を取得する
     *
     * @return array{bids: array<int, array{price: float, size: float}>, asks: array<int, array{price: float, size: float}>}
     * @throws \Exception API がエラーを返した場合
     */
    public function fetch(string $symbol): array
    {
        $gmoSymbol = $this->convertSymbol($symbol);

        $response = $this->httpClient->get("{$this->baseUrl}/v1/orderbooks", [
            'query' => ['symbol' => $gmoSymbol],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (($data['status'] ?? null) !== 0) {
            $messages = $data['messages'] ?? 'unknown error';
            throw new \Exception('GMO Coin API Error: ' . (is_array($messages) ? json_encode($messages) : $messages));
        }

        $book = $data['data'] ?? [];

        return [
            'bids' => $this->normalize($book['bids'] ?? []),
            'asks' => $this->normalize($book['asks'] ?? []),
        ];
    }

    /**
     * 価格・数量を数値に変換する
     *
     * bids は高い順、asks は安い順（APIの返却順）を前提とするが、
     * 念のため明示的に並べ替える。
     *
     * @return array<int, array{price: float, size: float}>
     */
    private function normalize(array $rows): array
    {
        $normalized = array_map(fn($row) => [
            'price' => (float) $row['price'],
            'size' => (float) $row['size'],
        ], $rows);

        return $normalized;
    }

    /**
     * BTC/JPY -> BTC のように GMO の銘柄表記へ変換
     */
    private function convertSymbol(string $symbol): string
    {
        return explode('/', $symbol)[0];
    }
}
