#!/bin/bash
# 取引詳細表示スクリプト

echo "========================================="
echo "  取引詳細レポート"
echo "========================================="
echo ""

# 全取引の統計
sqlite3 database/database.sqlite << 'EOF'
SELECT
    '総取引数: ' || COUNT(*) || '件' as stat
FROM positions
WHERE status = 'closed';

SELECT
    '勝ちトレード: ' || COUNT(*) || '件' as stat
FROM positions
WHERE status = 'closed' AND profit_loss > 0;

SELECT
    '負けトレード: ' || COUNT(*) || '件' as stat
FROM positions
WHERE status = 'closed' AND profit_loss < 0;

SELECT
    '勝率: ' || ROUND(CAST(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) AS FLOAT) / COUNT(*) * 100, 1) || '%' as stat
FROM positions
WHERE status = 'closed';

SELECT
    '合計損益: ' || ROUND(SUM(profit_loss), 2) || '円' as stat
FROM positions
WHERE status = 'closed';

SELECT
    '平均損益: ' || ROUND(AVG(profit_loss), 2) || '円' as stat
FROM positions
WHERE status = 'closed';

SELECT
    '最大利益: ' || ROUND(MAX(profit_loss), 2) || '円' as stat
FROM positions
WHERE status = 'closed' AND profit_loss > 0;

SELECT
    '最大損失: ' || ROUND(MIN(profit_loss), 2) || '円' as stat
FROM positions
WHERE status = 'closed' AND profit_loss < 0;
EOF

echo ""
echo "========================================="
echo "  全取引詳細"
echo "========================================="
echo ""

# 取引詳細を表形式で表示
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 12 12 10 10 10 6

SELECT
    id as '#',
    PRINTF('%,d', CAST(entry_price AS INTEGER)) || '円' as 'エントリー',
    PRINTF('%,d', CAST(exit_price AS INTEGER)) || '円' as 'エグジット',
    CASE
        WHEN profit_loss > 0 THEN '+' || ROUND(profit_loss, 2) || '円'
        ELSE ROUND(profit_loss, 2) || '円'
    END as '損益',
    CAST(ROUND((JULIANDAY(closed_at) - JULIANDAY(opened_at)) * 24 * 60) AS INTEGER) || '分' as '保有時間',
    CASE
        WHEN profit_loss > 0 THEN '✅'
        ELSE '❌'
    END as '結果'
FROM positions
WHERE status = 'closed'
ORDER BY id;
EOF

echo ""
echo "========================================="
echo "  オープンポジション"
echo "========================================="
echo ""

# オープンポジションがあるか確認
OPEN_COUNT=$(sqlite3 database/database.sqlite "SELECT COUNT(*) FROM positions WHERE status = 'open';")

if [ "$OPEN_COUNT" -gt 0 ]; then
    sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 12 10 20

SELECT
    id as '#',
    symbol as '通貨ペア',
    PRINTF('%,d', CAST(entry_price AS INTEGER)) || '円' as 'エントリー',
    quantity || ' BTC' as '数量',
    opened_at as 'オープン日時'
FROM positions
WHERE status = 'open'
ORDER BY id;
EOF
else
    echo "現在オープンポジションはありません"
fi

echo ""
echo "========================================="
echo "  時系列チャート"
echo "========================================="
echo ""

# 累積損益を計算して表示
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 20 10 12

SELECT
    id as '#',
    closed_at as 'クローズ時刻',
    CASE
        WHEN profit_loss > 0 THEN '+' || ROUND(profit_loss, 2)
        ELSE ROUND(profit_loss, 2)
    END as '損益',
    ROUND(SUM(profit_loss) OVER (ORDER BY id), 2) || '円' as '累積損益'
FROM positions
WHERE status = 'closed'
ORDER BY id;
EOF

echo ""
