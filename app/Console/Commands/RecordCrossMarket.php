<?php

namespace App\Console\Commands;

use App\Models\CrossMarketSnapshot;
use App\Trading\Market\CrossMarketFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 市場横断のスナップショットを記録する
 *
 * BTC/JPY 単体の価格・出来高・板情報からは手数料を超える優位性が
 * 見つからなかったため、市場間の関係の歪みを捉える方向に切り替える。
 *
 * 中心指標は日本プレミアム:
 *   premium = BTC/JPY ÷ (BTC/USD × USD/JPY) - 1
 *
 * 予備調査では振れ幅 0.270%（往復手数料 0.106% の2.5倍）を確認したが、
 * 為替の時間足履歴が60日分しか遡れず判定に至らなかった。
 * 過去に遡れない以上、今から蓄積するしかない。
 */
class RecordCrossMarket extends Command
{
    protected $signature = 'market:record';

    protected $description = '市場横断スナップショットを記録（日本プレミアム・取引所間価格差）';

    public function handle(CrossMarketFetcher $fetcher): int
    {
        try {
            $data = $fetcher->fetch();
        } catch (\Exception $e) {
            $this->error('取得失敗: ' . $e->getMessage());
            Log::warning('Cross market recording failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        // 主要3値が揃わないとプレミアムを計算できない
        if ($data['gmo_mid'] === null || $data['btc_usd'] === null || $data['usd_jpy'] === null) {
            $missing = [];
            foreach (['gmo_mid' => 'GMO', 'btc_usd' => 'Binance', 'usd_jpy' => '為替'] as $k => $label) {
                if ($data[$k] === null) {
                    $missing[] = $label;
                }
            }
            $this->warn('主要データ欠損のためプレミアム未計算: ' . implode(', ', $missing));
        } else {
            $data['fair_value_jpy'] = $data['btc_usd'] * $data['usd_jpy'];
            $data['premium_percent'] = ($data['gmo_mid'] / $data['fair_value_jpy'] - 1) * 100;
        }

        $snapshot = CrossMarketSnapshot::updateOrCreate(
            ['recorded_at' => now()->startOfMinute()],
            $data
        );

        if ($snapshot->premium_percent !== null) {
            $spread = $snapshot->domesticSpreadPercent();
            $this->line(sprintf(
                'BTC/JPY=%s 理論値=%s プレミアム=%+.4f%% 国内価格差=%s FX鮮度=%s',
                number_format($snapshot->gmo_mid),
                number_format($snapshot->fair_value_jpy),
                $snapshot->premium_percent,
                $spread !== null ? sprintf('%.4f%%', $spread) : '-',
                $snapshot->hasFreshFx() ? '取引時間内' : sprintf('%d分前', (int) (($snapshot->fx_age_seconds ?? 0) / 60))
            ));
        }

        return self::SUCCESS;
    }
}
