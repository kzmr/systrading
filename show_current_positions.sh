#!/bin/bash

# 複数戦略対応のポジション表示スクリプト
# 使い方:
#   ./show_current_positions.sh          # 全戦略を表示
#   ./show_current_positions.sh 5        # trading_settings ID=5 のみ
#   ./show_current_positions.sh XRP/JPY  # 通貨ペア指定（後方互換）

SETTING_ID="$1"

echo "========================================="
echo "  現在のポジション詳細"
echo "========================================="
echo ""

# アクティブな戦略一覧を取得
echo "--- アクティブな戦略 ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    id as 'ID',
    symbol as '通貨ペア',
    name as '戦略名',
    json_extract(parameters, '$.trade_size') as '取引サイズ'
FROM trading_settings
WHERE is_active = 1
ORDER BY id;
SQL
echo ""

# フィルター条件を設定
if [ -n "$SETTING_ID" ]; then
    if [[ "$SETTING_ID" =~ ^[0-9]+$ ]]; then
        # 数字の場合はID指定
        SYMBOL=$(sqlite3 database/database.sqlite "SELECT symbol FROM trading_settings WHERE id = ${SETTING_ID}")
        FILTER="AND symbol = '${SYMBOL}'"
        echo "フィルター: ID=${SETTING_ID} (${SYMBOL})"
    else
        # 文字列の場合は通貨ペア指定（後方互換）
        SYMBOL="$SETTING_ID"
        FILTER="AND symbol = '${SYMBOL}'"
        echo "フィルター: ${SYMBOL}"
    fi
else
    FILTER=""
    echo "フィルター: 全戦略"
fi
echo ""

# 各通貨ペアの現在価格とRSIを取得して表示
echo "--- 現在価格 & RSI ---"
SYMBOLS=$(sqlite3 database/database.sqlite "SELECT DISTINCT symbol FROM trading_settings WHERE is_active = 1")
for SYM in $SYMBOLS; do
    # RSI期間を取得（RSI戦略を優先、なければデフォルト60）
    RSI_PERIOD=$(sqlite3 database/database.sqlite "SELECT COALESCE(json_extract(parameters, '\$.rsi_period'), 60) FROM trading_settings WHERE symbol = '${SYM}' AND is_active = 1 AND strategy LIKE '%RSIContrarian%' LIMIT 1")
    # RSI戦略がない場合はデフォルト60
    if [ -z "$RSI_PERIOD" ]; then
        RSI_PERIOD=60
    fi

    # 価格とRSIを取得
    RESULT=$(php artisan tinker --execute="
\$client = new \App\Trading\Exchange\GMOCoinClient();
\$marketData = \$client->getMarketData('${SYM}', 100);
\$prices = \$marketData['prices'];
\$currentPrice = end(\$prices);

// RSI計算
\$period = ${RSI_PERIOD};
if (count(\$prices) >= \$period + 1) {
    \$gains = [];
    \$losses = [];
    for (\$i = count(\$prices) - \$period; \$i < count(\$prices); \$i++) {
        \$change = \$prices[\$i] - \$prices[\$i - 1];
        if (\$change > 0) {
            \$gains[] = \$change;
            \$losses[] = 0;
        } else {
            \$gains[] = 0;
            \$losses[] = abs(\$change);
        }
    }
    \$avgGain = array_sum(\$gains) / \$period;
    \$avgLoss = array_sum(\$losses) / \$period;
    if (\$avgLoss == 0) {
        \$rsi = 100;
    } else {
        \$rs = \$avgGain / \$avgLoss;
        \$rsi = round(100 - (100 / (1 + \$rs)), 2);
    }
} else {
    \$rsi = 'N/A';
}
echo \$currentPrice . '|' . \$rsi . '|' . \$period;
" 2>/dev/null | tail -1)

    PRICE=$(echo "$RESULT" | cut -d'|' -f1)
    RSI=$(echo "$RESULT" | cut -d'|' -f2)
    PERIOD=$(echo "$RESULT" | cut -d'|' -f3)

    # RSI状態を判定
    RSI_STATUS=""
    if [ "$RSI" != "N/A" ] && [ -n "$RSI" ]; then
        RSI_INT=$(echo "$RSI" | cut -d'.' -f1)
        if [ -n "$RSI_INT" ] && [ "$RSI_INT" -lt 30 ] 2>/dev/null; then
            RSI_STATUS="(売られすぎ)"
        elif [ -n "$RSI_INT" ] && [ "$RSI_INT" -gt 70 ] 2>/dev/null; then
            RSI_STATUS="(買われすぎ)"
        fi
    fi

    echo "${SYM}: ${PRICE}円 | RSI(${PERIOD})=${RSI} ${RSI_STATUS}"
done
echo ""

# オープンポジションの詳細
echo "--- オープンポジション ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    p.id as 'ID',
    p.symbol as '通貨ペア',
    p.side as 'サイド',
    p.quantity as '数量',
    p.entry_price as 'エントリー',
    ROUND(p.trailing_stop_price, 3) as 'トレーリングS',
    ROUND(p.exit_order_price, 3) as '決済指値',
    datetime(p.opened_at, 'localtime') as 'オープン日時'
FROM positions p
WHERE p.status = 'open' ${FILTER}
ORDER BY p.opened_at DESC;
SQL

echo ""
echo "--- オープンポジション合計 ---"
sqlite3 database/database.sqlite <<SQL
SELECT 'ポジション数: ' || COUNT(*) || '件' FROM positions WHERE status = 'open' ${FILTER}
UNION ALL
SELECT 'ロング: ' || COUNT(*) || '件' FROM positions WHERE status = 'open' AND side = 'long' ${FILTER}
UNION ALL
SELECT 'ショート: ' || COUNT(*) || '件' FROM positions WHERE status = 'open' AND side = 'short' ${FILTER};
SQL

echo ""
echo "--- 最新30件のクローズ済みポジション ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    p.id as 'ID',
    p.symbol as '通貨ペア',
    COALESCE(ts.name, '-') as '戦略',
    p.side as 'サイド',
    p.quantity as '数量',
    ROUND(p.entry_price, 3) as 'エントリー',
    ROUND(p.exit_price, 3) as 'エグジット',
    ROUND(p.profit_loss, 2) as '損益',
    ROUND(IFNULL(p.entry_fee, 0) + IFNULL(p.exit_fee, 0), 2) as '手数料',
    ROUND(p.profit_loss - (IFNULL(p.entry_fee, 0) + IFNULL(p.exit_fee, 0)), 2) as '純損益',
    ROUND((p.profit_loss / (p.entry_price * p.quantity)) * 100, 2) || '%' as '損益率',
    datetime(p.closed_at, 'localtime') as 'クローズ日時'
FROM positions p
LEFT JOIN trading_settings ts ON p.trading_settings_id = ts.id
WHERE p.status = 'closed' ${FILTER}
ORDER BY p.closed_at DESC
LIMIT 30;
SQL

echo ""
echo "--- 通貨ペア別統計 ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    symbol as '通貨ペア',
    COUNT(*) as '取引数',
    SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) as '勝ち',
    SUM(CASE WHEN profit_loss <= 0 THEN 1 ELSE 0 END) as '負け',
    ROUND(SUM(CASE WHEN profit_loss > 0 THEN 1.0 ELSE 0 END) / COUNT(*) * 100, 1) || '%' as '勝率',
    ROUND(SUM(IFNULL(profit_loss, 0)), 2) as '累計損益',
    ROUND(SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as '累計手数料',
    ROUND(SUM(IFNULL(profit_loss, 0)) - SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as '純損益'
FROM positions
WHERE status = 'closed' ${FILTER}
GROUP BY symbol;
SQL

echo ""
echo "--- 本日の取引統計 ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    symbol as '通貨ペア',
    COUNT(*) as '取引数',
    ROUND(SUM(IFNULL(profit_loss, 0)), 2) as '損益',
    ROUND(SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as '手数料',
    ROUND(SUM(IFNULL(profit_loss, 0)) - SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as '純損益'
FROM positions
WHERE status = 'closed'
AND DATE(closed_at) = DATE('now', 'localtime') ${FILTER}
GROUP BY symbol
UNION ALL
SELECT '合計', COUNT(*),
    ROUND(SUM(IFNULL(profit_loss, 0)), 2),
    ROUND(SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2),
    ROUND(SUM(IFNULL(profit_loss, 0)) - SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2)
FROM positions
WHERE status = 'closed'
AND DATE(closed_at) = DATE('now', 'localtime') ${FILTER};
SQL

echo ""
echo "========================================="
