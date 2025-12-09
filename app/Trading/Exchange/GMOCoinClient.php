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
            try {
                $todayData = $this->fetchKlines($gmoSymbol, '1min', $today);
                $prices = array_merge($prices, $todayData);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch today klines', ['error' => $e->getMessage()]);
            }

            // データが取得できなかった場合はエラー
            if (empty($prices)) {
                throw new \Exception('No price data available: both yesterday and today fetch failed');
            }

            // 最新のlimit件のみを返す（取得できたデータ数がlimitより少ない場合もある）
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
            $messages = is_array($data['messages']) ? json_encode($data['messages']) : $data['messages'];
            throw new \Exception("GMO Coin API Error: {$messages}");
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

            $orderId = $result['data'];

            // 成行注文の場合は約定情報APIから実際の約定価格を取得
            $executedPrice = $price;
            if ($price === null) {
                $executedPrice = $this->getExecutedPrice($orderId, $symbol);
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executedPrice,
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

            $orderId = $result['data'];

            // 成行注文の場合は約定情報APIから実際の約定価格を取得
            $executedPrice = $price;
            if ($price === null) {
                $executedPrice = $this->getExecutedPrice($orderId, $symbol);
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executedPrice,
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
     * 注文IDから約定情報を取得
     */
    public function getExecutionsByOrderId(string $orderId): array
    {
        try {
            $result = $this->sendPrivateRequest('GET', '/v1/executions', [
                'orderId' => $orderId,
            ]);

            return $result['data']['list'] ?? [];
        } catch (\Exception $e) {
            Log::error('GMO Coin executions fetch failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * 約定価格を取得（orderIdから）
     * 成行注文の実際の約定価格を取得するために使用
     */
    public function getExecutedPrice(string $orderId, string $symbol): float
    {
        // 約定情報を取得（最大3回リトライ、各500ms待機）
        for ($i = 0; $i < 3; $i++) {
            usleep(500000); // 500ms待機（約定処理完了を待つ）

            $executions = $this->getExecutionsByOrderId($orderId);

            if (!empty($executions)) {
                // 複数約定の場合は加重平均価格を計算
                $totalValue = 0;
                $totalSize = 0;
                foreach ($executions as $execution) {
                    $price = (float) $execution['price'];
                    $size = (float) $execution['size'];
                    $totalValue += $price * $size;
                    $totalSize += $size;
                }

                if ($totalSize > 0) {
                    $avgPrice = $totalValue / $totalSize;
                    Log::info('Execution price retrieved', [
                        'orderId' => $orderId,
                        'avgPrice' => $avgPrice,
                        'executionCount' => count($executions),
                    ]);
                    return $avgPrice;
                }
            }
        }

        // 約定情報が取得できない場合は現在価格を返す（フォールバック）
        Log::warning('Could not retrieve execution price, using current price', [
            'orderId' => $orderId,
        ]);
        return $this->getCurrentPrice($symbol);
    }

    /**
     * Private APIリクエストを送信
     */
    private function sendPrivateRequest(string $method, string $path, array $data = []): array
    {
        $timestamp = (string) (time() * 1000);

        // GETの場合はクエリパラメータ、POSTの場合はボディ
        $queryString = '';
        $body = '';
        if ($method === 'GET' && !empty($data)) {
            $queryString = '?' . http_build_query($data);
        } elseif (!empty($data)) {
            $body = json_encode($data);
        }

        // 署名を生成（GETの場合はクエリ付きパス、POSTの場合はパス+ボディ）
        $text = $timestamp . $method . $path . $queryString . $body;
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
            $this->privateBaseUrl . $path . $queryString,
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
     * 現在価格を取得（成行注文の約定価格推定用）
     */
    public function getCurrentPrice(string $symbol): float
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
            // lastは最終取引価格
            return (float) $ticker['last'];
        } catch (\Exception $e) {
            Log::error('GMO Coin current price fetch failed', ['error' => $e->getMessage()]);
            throw $e;
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
