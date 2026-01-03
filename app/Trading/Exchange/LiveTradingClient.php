<?php

namespace App\Trading\Exchange;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * 実際の取引所APIクライアント（例: Binance, Coinbase等）
 */
class LiveTradingClient implements ExchangeClient
{
    private Client $httpClient;
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('trading.exchange.api_key');
        $this->apiSecret = config('trading.exchange.api_secret');
        $this->baseUrl = config('trading.exchange.base_url');

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 10,
        ]);
    }

    public function getMarketData(string $symbol, int $limit = 100): array
    {
        try {
            // 実際の取引所APIを呼び出す
            // 以下は例（実際のAPIに合わせて実装が必要）
            $response = $this->httpClient->get('/api/v3/klines', [
                'query' => [
                    'symbol' => str_replace('/', '', $symbol),
                    'interval' => '1m',
                    'limit' => $limit,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // レスポンスを標準形式に変換
            $prices = array_map(function ($candle) {
                return (float) $candle[4]; // 終値
            }, $data);

            return [
                'symbol' => $symbol,
                'prices' => $prices,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Market data fetch failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function buy(string $symbol, float $quantity, ?float $price = null): array
    {
        try {
            $params = [
                'symbol' => str_replace('/', '', $symbol),
                'side' => 'BUY',
                'quantity' => $quantity,
            ];

            if ($price === null) {
                $params['type'] = 'MARKET';
            } else {
                $params['type'] = 'LIMIT';
                $params['price'] = $price;
                $params['timeInForce'] = 'GTC';
            }

            // 署名を追加（取引所のAPI仕様に従う）
            $params = $this->signRequest($params);

            $response = $this->httpClient->post('/api/v3/order', [
                'query' => $params,
                'headers' => [
                    'X-MBX-APIKEY' => $this->apiKey,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('Buy order executed', $result);

            // 成行注文の場合はfillsから手数料を取得
            $fee = 0;
            $executedPrice = $result['price'] ?? $price;
            if (isset($result['fills']) && !empty($result['fills'])) {
                $totalValue = 0;
                $totalQty = 0;
                foreach ($result['fills'] as $fill) {
                    $fee += (float) ($fill['commission'] ?? 0);
                    $totalValue += (float) $fill['price'] * (float) $fill['qty'];
                    $totalQty += (float) $fill['qty'];
                }
                if ($totalQty > 0) {
                    $executedPrice = $totalValue / $totalQty;
                }
            }

            return [
                'success' => true,
                'order_id' => $result['orderId'],
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executedPrice,
                'fee' => $fee,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Buy order failed', [
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

    public function sell(string $symbol, float $quantity, ?float $price = null): array
    {
        try {
            $params = [
                'symbol' => str_replace('/', '', $symbol),
                'side' => 'SELL',
                'quantity' => $quantity,
            ];

            if ($price === null) {
                $params['type'] = 'MARKET';
            } else {
                $params['type'] = 'LIMIT';
                $params['price'] = $price;
                $params['timeInForce'] = 'GTC';
            }

            $params = $this->signRequest($params);

            $response = $this->httpClient->post('/api/v3/order', [
                'query' => $params,
                'headers' => [
                    'X-MBX-APIKEY' => $this->apiKey,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('Sell order executed', $result);

            // 成行注文の場合はfillsから手数料を取得
            $fee = 0;
            $executedPrice = $result['price'] ?? $price;
            if (isset($result['fills']) && !empty($result['fills'])) {
                $totalValue = 0;
                $totalQty = 0;
                foreach ($result['fills'] as $fill) {
                    $fee += (float) ($fill['commission'] ?? 0);
                    $totalValue += (float) $fill['price'] * (float) $fill['qty'];
                    $totalQty += (float) $fill['qty'];
                }
                if ($totalQty > 0) {
                    $executedPrice = $totalValue / $totalQty;
                }
            }

            return [
                'success' => true,
                'order_id' => $result['orderId'],
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $executedPrice,
                'fee' => $fee,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('Sell order failed', [
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

    public function getBalance(): array
    {
        try {
            $params = $this->signRequest(['timestamp' => now()->timestamp * 1000]);

            $response = $this->httpClient->get('/api/v3/account', [
                'query' => $params,
                'headers' => [
                    'X-MBX-APIKEY' => $this->apiKey,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $balance = [];
            foreach ($data['balances'] as $asset) {
                if ($asset['free'] > 0 || $asset['locked'] > 0) {
                    $balance[$asset['asset']] = (float) $asset['free'];
                }
            }

            return $balance;
        } catch (\Exception $e) {
            Log::error('Balance fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getOpenPositions(): array
    {
        try {
            $params = $this->signRequest(['timestamp' => now()->timestamp * 1000]);

            $response = $this->httpClient->get('/api/v3/openOrders', [
                'query' => $params,
                'headers' => [
                    'X-MBX-APIKEY' => $this->apiKey,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Open positions fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function signRequest(array $params): array
    {
        $params['timestamp'] = $params['timestamp'] ?? now()->timestamp * 1000;
        $queryString = http_build_query($params);
        $signature = hash_hmac('sha256', $queryString, $this->apiSecret);
        $params['signature'] = $signature;

        return $params;
    }

    public function getSpread(string $symbol): float
    {
        try {
            // Binanceのティッカー情報を取得
            $response = $this->httpClient->get('/api/v3/ticker/bookTicker', [
                'query' => [
                    'symbol' => str_replace('/', '', $symbol),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $spread = (float)$data['askPrice'] - (float)$data['bidPrice'];

            return $spread;
        } catch (\Exception $e) {
            Log::error('Binance spread fetch failed', ['error' => $e->getMessage()]);
            return 999999.0;
        }
    }
}
