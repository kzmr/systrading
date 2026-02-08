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
                $orderData['price'] = $this->formatPrice($symbol, $price);
            }

            $result = $this->sendPrivateRequest('POST', '/v1/order', $orderData);

            Log::info('GMO Coin buy order executed', $result);

            $orderId = $result['data'];

            // 成行注文の場合は約定情報APIから実際の約定価格と手数料を取得
            $executedPrice = $price;
            $fee = 0;
            if ($price === null) {
                $executionDetails = $this->getExecutionDetails($orderId, $symbol);
                $executedPrice = $executionDetails['price'];
                $fee = $executionDetails['fee'];
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executedPrice,
                'fee' => $fee,
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
                $orderData['price'] = $this->formatPrice($symbol, $price);
            }

            $result = $this->sendPrivateRequest('POST', '/v1/order', $orderData);

            Log::info('GMO Coin sell order executed', $result);

            $orderId = $result['data'];

            // 成行注文の場合は約定情報APIから実際の約定価格と手数料を取得
            $executedPrice = $price;
            $fee = 0;
            if ($price === null) {
                $executionDetails = $this->getExecutionDetails($orderId, $symbol);
                $executedPrice = $executionDetails['price'];
                $fee = $executionDetails['fee'];
            }

            return [
                'success' => true,
                'order_id' => $orderId,
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executedPrice,
                'fee' => $fee,
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
     * 注文をキャンセル
     *
     * @see https://api.coin.z.com/docs/#cancel
     */
    public function cancelOrder(string $orderId): array
    {
        try {
            $result = $this->sendPrivateRequest('POST', '/v1/cancelOrder', [
                'orderId' => $orderId,
            ]);

            Log::info('GMO Coin order canceled', [
                'orderId' => $orderId,
                'result' => $result,
            ]);

            return [
                'success' => true,
                'order_id' => $orderId,
            ];
        } catch (\Exception $e) {
            Log::warning('GMO Coin cancel order failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 注文状態を取得
     *
     * @see https://api.coin.z.com/docs/#orders
     * @return array ['status' => 'EXECUTED'|'WAITING'|'CANCELED'|'EXPIRED'|'NOT_FOUND', ...]
     */
    public function getOrderStatus(string $orderId): array
    {
        try {
            $result = $this->sendPrivateRequest('GET', '/v1/orders', [
                'orderId' => $orderId,
            ]);

            $orders = $result['data']['list'] ?? [];

            if (empty($orders)) {
                return [
                    'status' => 'NOT_FOUND',
                    'order_id' => $orderId,
                ];
            }

            $order = $orders[0];

            return [
                'status' => $order['status'], // WAITING, EXECUTED, CANCELED, EXPIRED
                'order_id' => $orderId,
                'side' => $order['side'] ?? null,
                'executionType' => $order['executionType'] ?? null,
                'price' => isset($order['price']) ? (float) $order['price'] : null,
                'size' => isset($order['size']) ? (float) $order['size'] : null,
                'executedSize' => isset($order['executedSize']) ? (float) $order['executedSize'] : null,
            ];
        } catch (\Exception $e) {
            Log::error('GMO Coin get order status failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'ERROR',
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ];
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

            $list = $result['data']['list'] ?? [];

            if (empty($list)) {
                Log::debug('No executions found for order', [
                    'orderId' => $orderId,
                    'response' => $result,
                ]);
            }

            return $list;
        } catch (\Exception $e) {
            Log::error('GMO Coin executions fetch failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * 約定情報を取得（orderIdから）
     * 成行注文の実際の約定価格と手数料を取得するために使用
     *
     * @return array ['price' => float, 'fee' => float]
     */
    public function getExecutionDetails(string $orderId, string $symbol): array
    {
        // 約定情報を取得（最大5回リトライ、各1秒待機）
        for ($i = 0; $i < 5; $i++) {
            usleep(1000000); // 1秒待機（約定処理完了を待つ）

            $executions = $this->getExecutionsByOrderId($orderId);

            Log::debug('Checking executions for order', [
                'orderId' => $orderId,
                'attempt' => $i + 1,
                'executionsCount' => count($executions),
            ]);

            if (!empty($executions)) {
                // 複数約定の場合は加重平均価格を計算、手数料は合計
                $totalValue = 0;
                $totalSize = 0;
                $totalFee = 0;
                foreach ($executions as $execution) {
                    $price = (float) $execution['price'];
                    $size = (float) $execution['size'];
                    $fee = (float) ($execution['fee'] ?? 0);
                    $totalValue += $price * $size;
                    $totalSize += $size;
                    $totalFee += $fee;
                }

                if ($totalSize > 0) {
                    $avgPrice = $totalValue / $totalSize;
                    Log::info('Execution details retrieved', [
                        'orderId' => $orderId,
                        'avgPrice' => $avgPrice,
                        'totalFee' => $totalFee,
                        'executionCount' => count($executions),
                    ]);
                    return [
                        'price' => $avgPrice,
                        'fee' => $totalFee,
                    ];
                }
            }
        }

        // 約定情報が取得できない場合は現在価格を返す（フォールバック）
        Log::warning('Could not retrieve execution details after 5 attempts, using current price', [
            'orderId' => $orderId,
        ]);
        return [
            'price' => $this->getCurrentPrice($symbol),
            'fee' => 0,
        ];
    }

    /**
     * 約定価格を取得（後方互換性のため）
     */
    public function getExecutedPrice(string $orderId, string $symbol): float
    {
        $details = $this->getExecutionDetails($orderId, $symbol);
        return $details['price'];
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

        // 署名を生成（GMO Coin APIはパスのみ、クエリパラメータは含めない）
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


    /**
     * 価格を通貨ペアに応じた小数点桁数にフォーマット
     * GMOコインのJPYペアは整数のみ受け付ける
     */
    private function formatPrice(string $symbol, float $price): string
    {
        // JPYペアは整数に丸める
        if (str_contains($symbol, '/JPY')) {
            return (string) round($price);
        }

        // その他は小数点2桁
        return number_format($price, 2, '.', '');
    }
}
