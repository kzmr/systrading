<?php

namespace App\Trading\Market;

use GuzzleHttp\Client;

/**
 * S&P500 の当日セッション情報を取得する
 *
 * バックテストと同じ計算方法を使う必要がある:
 *   セッション変動率 = (その日の最後の時間足終値 / 最初の時間足終値 - 1) × 100
 *
 * 米国市場の開閉時刻は夏時間・冬時間で1時間ずれるため、
 * 固定値ではなく実際に返ってきた時間足から判定する。
 */
class SpxSessionFetcher
{
    private Client $httpClient;

    /** セッション完了とみなすまでの、最終足からの経過時間 */
    private const COMPLETE_AFTER_MINUTES = 15;

    /** セッションとして成立する最低本数（半日立会いなどを除外しすぎない範囲） */
    private const MIN_BARS = 4;

    public function __construct(?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 10,
            'headers' => ['User-Agent' => 'Mozilla/5.0'],
        ]);
    }

    /**
     * 最新セッションの情報を取得する
     *
     * @return array{session_date: string, first_close: float, last_close: float, bar_count: int, session_move_percent: float, last_bar_at: int, is_complete: bool}|null
     * @throws \Exception API がエラーを返した場合
     */
    public function fetchLatestSession(): ?array
    {
        $response = $this->httpClient->get(
            'https://query1.finance.yahoo.com/v8/finance/chart/%5EGSPC',
            ['query' => ['range' => '5d', 'interval' => '1h']]
        );

        $data = json_decode($response->getBody()->getContents(), true);
        $result = $data['chart']['result'][0] ?? null;

        if (!$result || empty($result['timestamp'])) {
            throw new \Exception('S&P500 のデータを取得できませんでした');
        }

        $closes = $result['indicators']['quote'][0]['close'] ?? [];

        // 日付ごとに時間足をまとめる（UTC基準。バックテストと同じ扱い）
        $byDate = [];
        foreach ($result['timestamp'] as $i => $ts) {
            $close = $closes[$i] ?? null;
            if ($close === null) {
                continue;
            }
            $date = gmdate('Y-m-d', $ts);
            $byDate[$date][] = ['ts' => $ts, 'close' => (float) $close];
        }

        if (empty($byDate)) {
            return null;
        }

        ksort($byDate);
        $latestDate = array_key_last($byDate);
        $bars = $byDate[$latestDate];

        if (count($bars) < self::MIN_BARS) {
            // 立会い開始直後などデータが不足している場合は判定しない
            return null;
        }

        $first = $bars[0];
        $last = $bars[count($bars) - 1];

        if ($first['close'] <= 0) {
            return null;
        }

        return [
            'session_date' => $latestDate,
            'first_close' => $first['close'],
            'last_close' => $last['close'],
            'bar_count' => count($bars),
            'session_move_percent' => ($last['close'] / $first['close'] - 1) * 100,
            'last_bar_at' => $last['ts'],
            // 最終足から一定時間が経過していればセッション完了とみなす
            'is_complete' => (time() - $last['ts']) >= self::COMPLETE_AFTER_MINUTES * 60,
        ];
    }
}
