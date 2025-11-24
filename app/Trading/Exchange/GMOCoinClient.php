<?php

namespace App\Trading\Exchange;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * GMOコイン取引所APIクライアント
 *
 * @see https://api.coin.z.com/docs/
 */
class GMOCoinClient implements ExchangeClient
{
    private Client $httpClient;
    private string $apiKey;
    private string $apiSecret;
    private string $publicBaseUrl = 'https://api.coin.z.com/public';
    private string $privateBaseUrl = 'https://api.coin.z.com/private';

    public function __construct()
    {
        $this->apiKey = config('trading.exchange.api_key');
        $this->apiSecret = config('trading.exchange.api_secret');

        $this->httpClient = new Client([
            'timeout' => 10,
        ]);
    }

    /**
     * 市場データを取得
     */
    public function getMarketData(string $symbol, int $limit = 100): array
    {
        try {
            // GMOコインのシンボル形式に変換 (BTC/USDT -> BTC)
            $gmoSymbol = $this->convertSymbol($symbol);

            // 日付を取得（今日と昨日）
            $today = now()->format('Ymd');
            $yesterday = now()->subDay()->format('Ymd');

            $prices = [];

            // 昨日のデータを取得
            try {
                $yesterdayData = $this->fetchKlines($gmoSymbol, '1min', $yesterday);
                $prices = array_merge($prices, $yesterdayData);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch yesterday klines', ['error' => $e->getMessage()]);
            }

            // 今日のデータを取得
            $todayData = $this->fetchKlines($gmoSymbol, '1min', $today);
            $prices = array_merge($prices, $todayData);

            // 最新のlimit件のみを返す
            $prices = array_slice($prices, -$limit);

            return [
                'symbol' => $symbol,
                'prices' => $prices,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('GMO Coin market data fetch failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * ローソク足データを取得
     */
    private function fetchKlines(string $symbol, string $interval, string $date): array
    {
        $response = $this->httpClient->get("{$this->publicBaseUrl}/v1/klines", [
            'query' => [
                'symbol' => $symbol,
                'interval' => $interval,
                'date' => $date,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if ($data['status'] !== 0) {
            throw new \Exception("GMO Coin API Error: {$data['messages']}");
        }

        // 終値のみを抽出
        return array_map(function ($candle) {
            return (float) $candle['close'];
        }, $data['data'] ?? []);
    }

    /**
     * 買い注文を実行
     */
    public function buy(string $symbol, float $quantity, ?float $price = null): array
    {
        try {
            $gmoSymbol = $this->convertSymbol($symbol);

            $orderData = [
                'symbol' => $gmoSymbol,
                'side' => 'BUY',
                'executionType' => $price === null ? 'MARKET' : 'LIMIT',
                'size' => (string) $quantity,
            ];

            if ($price !== null) {
                $orderData['price'] = (string) $price;
            }

            $result = $this->sendPrivateRequest('POST', '/v1/order', $orderData);

            Log::info('GMO Coin buy order executed', $result);

            return [
                'success' => true,
                'order_id' => $result['data'],
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $price,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('GMO Coin buy order failed', [
                'symbol' => $symbol,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 売り注文を実行
     */
    public function sell(string $symbol, float $quantity, ?float $price = null): array
    {
        try {
            $gmoSymbol = $this->convertSymbol($symbol);

            $orderData = [
                'symbol' => $gmoSymbol,
                'side' => 'SELL',
                'executionType' => $price === null ? 'MARKET' : 'LIMIT',
                'size' => (string) $quantity,
            ];

            if ($price !== null) {
                $orderData['price'] = (string) $price;
            }

            $result = $this->sendPrivateRequest('POST', '/v1/order', $orderData);

            Log::info('GMO Coin sell order executed', $result);

            return [
                'success' => true,
                'order_id' => $result['data'],
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $price,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('GMO Coin sell order failed', [
                'symbol' => $symbol,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 残高を取得
     */
    public function getBalance(): array
    {
        try {
            $result = $this->sendPrivateRequest('GET', '/v1/account/assets');

            $balance = [];
            foreach ($result['data'] ?? [] as $asset) {
                $balance[$asset['symbol']] = (float) $asset['available'];
            }

            return $balance;
        } catch (\Exception $e) {
            Log::error('GMO Coin balance fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * オープンポジション（有効注文）を取得
     */
    public function getOpenPositions(): array
    {
        try {
            $result = $this->sendPrivateRequest('GET', '/v1/activeOrders');

            return $result['data']['list'] ?? [];
        } catch (\Exception $e) {
            Log::error('GMO Coin open positions fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Private APIリクエストを送信
     */
    private function sendPrivateRequest(string $method, string $path, array $data = []): array
    {
        $timestamp = (string) (time() * 1000);
        $body = empty($data) ? '' : json_encode($data);

        // 署名を生成
        $text = $timestamp . $method . $path . $body;
        $sign = hash_hmac('sha256', $text, $this->apiSecret);

        $options = [
            'headers' => [
                'API-KEY' => $this->apiKey,
                'API-TIMESTAMP' => $timestamp,
                'API-SIGN' => $sign,
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($body)) {
            $options['body'] = $body;
        }

        $response = $this->httpClient->request(
            $method,
            $this->privateBaseUrl . $path,
            $options
        );

        $result = json_decode($response->getBody()->getContents(), true);

        if ($result['status'] !== 0) {
            throw new \Exception("GMO Coin API Error: " . json_encode($result['messages']));
        }

        return $result;
    }

    /**
     * 現在のスプレッドを取得
     */
    public function getSpread(string $symbol): float
    {
        try {
            $gmoSymbol = $this->convertSymbol($symbol);

            $response = $this->httpClient->get("{$this->publicBaseUrl}/v1/ticker", [
                'query' => [
                    'symbol' => $gmoSymbol,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['status'] !== 0 || !isset($data['data'][0])) {
                throw new \Exception("GMO Coin API Error: Failed to get ticker");
            }

            $ticker = $data['data'][0];
            $spread = (float)$ticker['ask'] - (float)$ticker['bid'];

            Log::info('Spread check', [
                'symbol' => $symbol,
                'bid' => $ticker['bid'],
                'ask' => $ticker['ask'],
                'spread' => $spread,
            ]);

            return $spread;
        } catch (\Exception $e) {
            Log::error('GMO Coin spread fetch failed', ['error' => $e->getMessage()]);
            // エラー時は大きな値を返して取引を抑制
            return 999999.0;
        }
    }

    /**
     * シンボル形式を変換
     * BTC/USDT -> BTC, ETH/USDT -> ETH
     */
    private function convertSymbol(string $symbol): string
    {
        // GMOコインはベース通貨のみを使用
        return explode('/', $symbol)[0];
    }
}
