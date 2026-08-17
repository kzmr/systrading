<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\TradingLog;
use App\Models\TradingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 戦略の撤退基準を監視し、条件を満たしたら自動停止する
 *
 * 手動監視だと「もう少し様子を見よう」で判断が先延ばしになる。
 * 撤退基準は稼働前に決め、機械的に執行する。
 *
 * trading_settings.parameters に以下を設定した戦略が対象:
 *
 * - max_cumulative_loss: 累計純損益がこの額のマイナスに達したら停止
 * - evaluation_trade_count: この取引数に達した時点で期待値を評価し、
 *                           プラスでなければ停止
 *
 * 判定はすべて手数料控除後の純損益で行う。
 */
class GuardStrategyLimits extends Command
{
    protected $signature = 'strategy:guard {--dry-run : 停止せず判定結果のみ表示}';

    protected $description = '戦略の撤退基準を監視し、条件を満たしたら自動停止';

    public function handle(): int
    {
        $settings = TradingSettings::where('is_active', true)->get();

        if ($settings->isEmpty()) {
            $this->line('稼働中の戦略はありません');
            return self::SUCCESS;
        }

        foreach ($settings as $setting) {
            $params = $setting->parameters ?? [];
            $maxLoss = $params['max_cumulative_loss'] ?? null;
            $evalCount = $params['evaluation_trade_count'] ?? null;

            if ($maxLoss === null && $evalCount === null) {
                continue;
            }

            $stats = $this->statsFor($setting);

            $this->line(sprintf(
                '[%d] %s: %d取引 純損益%+.1f円 平均%+.2f円',
                $setting->id,
                $setting->name,
                $stats['count'],
                $stats['total'],
                $stats['mean']
            ));

            $reason = $this->breachedReason($stats, $maxLoss, $evalCount);

            if ($reason === null) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->warn("  → 停止条件に該当（dry-run のため停止しません）: {$reason}");
                continue;
            }

            $this->stopStrategy($setting, $reason, $stats);
        }

        return self::SUCCESS;
    }

    /**
     * 撤退基準に抵触したか判定する
     *
     * @return string|null 抵触した理由。抵触していなければ null
     */
    private function breachedReason(array $stats, ?float $maxLoss, ?int $evalCount): ?string
    {
        // 基準A: 累計損失の上限
        if ($maxLoss !== null && $stats['total'] <= -abs($maxLoss)) {
            return sprintf('累計損失が上限に到達（%.1f円 <= -%.1f円）', $stats['total'], abs($maxLoss));
        }

        // 基準B: 一定取引数での期待値評価
        // 取引が無い状態で平均0を「マイナスでない」と誤判定しないよう件数を先に確認する
        if ($evalCount !== null && $stats['count'] >= $evalCount && $stats['mean'] <= 0) {
            return sprintf('%d取引時点の期待値がプラスでない（平均 %+.2f円）', $stats['count'], $stats['mean']);
        }

        return null;
    }

    /**
     * 戦略のクローズ済みポジションの成績を集計する
     *
     * @return array{count: int, total: float, mean: float}
     */
    private function statsFor(TradingSettings $setting): array
    {
        $positions = Position::where('trading_settings_id', $setting->id)
            ->where('status', 'closed')
            ->whereNotNull('profit_loss')
            ->get();

        $total = 0.0;
        foreach ($positions as $p) {
            $total += $p->net_profit_loss;
        }

        $count = $positions->count();

        return [
            'count' => $count,
            'total' => $total,
            'mean' => $count > 0 ? $total / $count : 0.0,
        ];
    }

    private function stopStrategy(TradingSettings $setting, string $reason, array $stats): void
    {
        $setting->update(['is_active' => false]);

        $message = sprintf(
            '戦略を自動停止しました: %s（%s / %d取引 純損益%+.1f円）',
            $setting->name,
            $reason,
            $stats['count'],
            $stats['total']
        );

        $this->error('  → ' . $message);

        Log::warning('Strategy auto-stopped by guard', [
            'setting_id' => $setting->id,
            'name' => $setting->name,
            'reason' => $reason,
            'trade_count' => $stats['count'],
            'net_profit_loss' => $stats['total'],
        ]);

        TradingLog::create([
            'symbol' => $setting->symbol,
            'action' => 'strategy_auto_stopped',
            'message' => $message,
            'executed_at' => now(),
        ]);
    }
}
