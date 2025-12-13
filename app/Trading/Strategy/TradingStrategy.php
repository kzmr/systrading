<?php

namespace App\Trading\Strategy;

use App\Models\TradingSettings;

/**
 * トレーディング戦略の基底クラス
 */
abstract class TradingStrategy
{
    protected TradingSettings $settings;

    public function __construct(TradingSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * 市場データを分析して取引シグナルを生成
     *
     * @param array $marketData 市場データ（価格、出来高など）
     * @return array ['action' => 'buy'|'sell'|'hold', 'quantity' => float, 'price' => float]
     */
    abstract public function analyze(array $marketData): array;

    /**
     * ストラテジーのパラメータを取得
     */
    public function getParameters(): array
    {
        return $this->settings->parameters ?? [];
    }

    /**
     * 戦略名を取得
     */
    public function getName(): string
    {
        return $this->settings->name ?? class_basename(static::class);
    }
}
