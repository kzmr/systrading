#!/bin/bash
# ポジション確認スクリプト

echo "==================================="
echo "  現在のポジション状況"
echo "==================================="
echo ""

echo "--- オープンポジション ---"
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 6 10 12 12 8 12 20
SELECT id, symbol, side, quantity, entry_price, exit_price, status, profit_loss, opened_at
FROM positions
WHERE status = 'open'
ORDER BY opened_at DESC;
EOF

echo ""
echo "--- 最新5件のクローズポジション ---"
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 6 10 12 12 12 20
SELECT id, symbol, side, quantity, entry_price, exit_price, profit_loss, closed_at
FROM positions
WHERE status = 'closed'
ORDER BY closed_at DESC
LIMIT 5;
EOF

echo ""
echo "--- ポジション統計 ---"
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT
    status,
    COUNT(*) as count,
    SUM(profit_loss) as total_profit
FROM positions
GROUP BY status;
EOF

echo ""
echo "--- 最新の取引ログ（5件） ---"
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 6 10 30 20
SELECT id, symbol, action, quantity, message, executed_at
FROM trading_logs
ORDER BY executed_at DESC
LIMIT 5;
EOF
