#!/bin/bash

# 通貨ペアを引数から取得（デフォルトはXRP/JPY）
SYMBOL="${1:-XRP/JPY}"

# 現在の価格を取得
CURRENT_PRICE=$(php artisan tinker --execute="
\$client = new \App\Trading\Exchange\GMOCoinClient();
\$marketData = \$client->getMarketData('${SYMBOL}', 1);
echo end(\$marketData['prices']);
" 2>/dev/null | tail -1)

echo "========================================="
echo "  現在のポジション詳細"
echo "========================================="
echo ""
echo "現在の${SYMBOL}価格: ${CURRENT_PRICE}円"
echo ""

# オープンポジションの詳細
echo "--- オープンポジション ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    id as 'ID',
    symbol as '通貨ペア',
    side as 'サイド',
    quantity as '数量',
    entry_price as 'エントリー価格',
    ROUND(
        CASE
            WHEN side = 'long' THEN ${CURRENT_PRICE} - entry_price
            WHEN side = 'short' THEN entry_price - ${CURRENT_PRICE}
            ELSE 0
        END,
        2
    ) as '価格変動',
    ROUND(
        CASE
            WHEN side = 'long' THEN (${CURRENT_PRICE} - entry_price) * quantity
            WHEN side = 'short' THEN (entry_price - ${CURRENT_PRICE}) * quantity
            ELSE 0
        END,
        4
    ) as '未実現損益',
    ROUND(
        CASE
            WHEN side = 'long' THEN ((${CURRENT_PRICE} - entry_price) / entry_price * 100)
            WHEN side = 'short' THEN ((entry_price - ${CURRENT_PRICE}) / entry_price * 100)
            ELSE 0
        END,
        2
    ) || '%' as '損益率',
    datetime(opened_at, 'localtime') as 'オープン日時',
    ROUND((JULIANDAY('now') - JULIANDAY(opened_at)) * 24, 1) as '保有時間(h)'
FROM positions
WHERE status = 'open'
ORDER BY opened_at DESC;
SQL

echo ""
echo "--- オープンポジション合計 ---"
sqlite3 database/database.sqlite <<SQL
SELECT
    'ポジション数: ' || COUNT(*) || '件' as summary
FROM positions
WHERE status = 'open'
UNION ALL
SELECT
    'ロング: ' || COUNT(*) || '件'
FROM positions
WHERE status = 'open' AND side = 'long'
UNION ALL
SELECT
    'ショート: ' || COUNT(*) || '件'
FROM positions
WHERE status = 'open' AND side = 'short'
UNION ALL
SELECT
    '合計未実現損益: ' || ROUND(SUM(
        CASE
            WHEN side = 'long' THEN (${CURRENT_PRICE} - entry_price) * quantity
            WHEN side = 'short' THEN (entry_price - ${CURRENT_PRICE}) * quantity
            ELSE 0
        END
    ), 4) || '円'
FROM positions
WHERE status = 'open';
SQL

echo ""
echo "--- 最新10件のクローズ済みポジション ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    id as 'ID',
    symbol as '通貨ペア',
    side as 'サイド',
    quantity as '数量',
    entry_price as 'エントリー',
    exit_price as 'エグジット',
    ROUND(profit_loss, 4) as '損益',
    ROUND((profit_loss / (entry_price * quantity)) * 100, 2) || '%' as '損益率',
    datetime(closed_at, 'localtime') as 'クローズ日時',
    ROUND((JULIANDAY(closed_at) - JULIANDAY(opened_at)) * 24, 1) as '保有時間(h)'
FROM positions
WHERE status = 'closed'
ORDER BY closed_at DESC
LIMIT 10;
SQL

echo ""
echo "--- 全ポジション統計 ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    status as 'ステータス',
    COUNT(*) as '件数',
    ROUND(SUM(IFNULL(profit_loss, 0)), 4) as '累計損益'
FROM positions
GROUP BY status;
SQL

echo ""
echo "--- 本日の取引統計 ---"
sqlite3 database/database.sqlite <<SQL
SELECT
    '本日の取引数: ' || COUNT(*) || '件'
FROM positions
WHERE DATE(opened_at) = DATE('now', 'localtime')
UNION ALL
SELECT
    '本日の損益: ' || ROUND(IFNULL(SUM(profit_loss), 0), 4) || '円'
FROM positions
WHERE status = 'closed'
AND DATE(closed_at) = DATE('now', 'localtime');
SQL

echo ""
echo "========================================="
