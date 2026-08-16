<?php

namespace App\Trading\Market;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;

/**
 * 複数市場の価格を「ほぼ同時刻」に取得する
 *
 * 時刻がずれると、その間の値動きが偽の乖離として現れる。
 * 日足で検証した際、GMO(21:00 UTC区切り)・Binance(00:00 UTC区切り)・
 * Yahoo(23:00 UTC区切り)がずれており、標準偏差2.3%という偽のプレミアムが出た。
 * そのため全ソースを並列リクエストして取得時刻を揃える。
 */
class CrossMarketFetcher
{
    private Client $httpClient;

    /** 1ソースの遅延が全体の同時性を壊さないよう短めに設定する */
    private const TIMEOUT = 5;

    public function __construct(?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => self::TIMEOUT,
            'headers' => ['User-Agent' => 'Mozilla/5.0'],
        ]);
    }

    /**
     * 全市場のスナップショットを取得する
     *
     * 個々のソースの失敗は null として返し、他のソースの記録を妨げない。
     *
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        $requests = [
            'gmo' => $this->httpClient->getAsync('https://api.coin.z.com/public/v1/ticker?symbol=BTC'),
            'bitflyer' => $this->httpClient->getAsync('https://api.bitflyer.com/v1/ticker?product_code=BTC_JPY'),
            'coincheck' => $this->httpClient->getAsync('https://coincheck.com/api/ticker'),
            'bitbank' => $this->httpClient->getAsync('https://public.bitbank.cc/btc_jpy/ticker'),
            'binance' => $this->httpClient->getAsync('https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT'),
            'fx' => $this->httpClient->getAsync('https://query1.finance.yahoo.com/v8/finance/chart/JPY=X?range=1d&interval=1m'),
        ];

        $responses = Utils::settle($requests)->wait();

        $body = function (string $key) use ($responses): ?array {
            $r = $responses[$key] ?? null;
            if (($r['state'] ?? null) !== 'fulfilled') {
                return null;
            }

            return json_decode($r['value']->getBody()->getContents(), true);
        };

        $result = [
            'gmo_bid' => null, 'gmo_ask' => null, 'gmo_mid' => null,
            'bitflyer_mid' => null, 'coincheck_mid' => null, 'bitbank_mid' => null,
            'btc_usd' => null, 'usd_jpy' => null, 'fx_age_seconds' => null,
        ];

        if ($d = $body('gmo')) {
            if (($d['status'] ?? null) === 0 && !empty($d['data'][0])) {
                $result['gmo_bid'] = (float) $d['data'][0]['bid'];
                $result['gmo_ask'] = (float) $d['data'][0]['ask'];
                $result['gmo_mid'] = ($result['gmo_bid'] + $result['gmo_ask']) / 2;
            }
        }

        if ($d = $body('bitflyer')) {
            if (isset($d['best_bid'], $d['best_ask'])) {
                $result['bitflyer_mid'] = ((float) $d['best_bid'] + (float) $d['best_ask']) / 2;
            }
        }

        if ($d = $body('coincheck')) {
            if (isset($d['bid'], $d['ask'])) {
                $result['coincheck_mid'] = ((float) $d['bid'] + (float) $d['ask']) / 2;
            }
        }

        if ($d = $body('bitbank')) {
            if (isset($d['data']['buy'], $d['data']['sell'])) {
                $result['bitbank_mid'] = ((float) $d['data']['buy'] + (float) $d['data']['sell']) / 2;
            }
        }

        if ($d = $body('binance')) {
            if (isset($d['price'])) {
                $result['btc_usd'] = (float) $d['price'];
            }
        }

        if ($d = $body('fx')) {
            $meta = $d['chart']['result'][0]['meta'] ?? null;
            if ($meta && isset($meta['regularMarketPrice'])) {
                $result['usd_jpy'] = (float) $meta['regularMarketPrice'];
                // FX市場は土日に閉じる。暗号資産は24時間動くため、
                // 為替の古さを記録しておかないと分析時に区別できない。
                if (isset($meta['regularMarketTime'])) {
                    $result['fx_age_seconds'] = max(0, time() - (int) $meta['regularMarketTime']);
                }
            }
        }

        return $result;
    }
}
