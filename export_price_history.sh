#!/bin/bash

# 価格履歴をCSVにエクスポートするスクリプト
# 使い方: ./export_price_history.sh [通貨ペア] [開始日時] [終了日時]

SYMBOL="${1:-XRP/JPY}"
START_DATE="${2:-}"
END_DATE="${3:-}"
OUTPUT_FILE="price_history_${SYMBOL//\//_}_$(date '+%Y%m%d%H%M%S').csv"

echo "========================================="
echo "  価格履歴エクスポート"
echo "========================================="
echo "通貨ペア: ${SYMBOL}"
echo "出力ファイル: ${OUTPUT_FILE}"
echo ""

# WHERE句の構築
WHERE_CLAUSE="WHERE symbol = '${SYMBOL}'"

if [ -n "$START_DATE" ]; then
    WHERE_CLAUSE="${WHERE_CLAUSE} AND recorded_at >= '${START_DATE}'"
    echo "開始日時: ${START_DATE}"
fi

if [ -n "$END_DATE" ]; then
    WHERE_CLAUSE="${WHERE_CLAUSE} AND recorded_at <= '${END_DATE}'"
    echo "終了日時: ${END_DATE}"
fi

echo ""
echo "データをエクスポート中..."

# CSVヘッダー
echo "id,symbol,price,recorded_at,created_at,updated_at" > "$OUTPUT_FILE"

# データをエクスポート
sqlite3 -csv database/database.sqlite <<SQL >> "$OUTPUT_FILE"
SELECT
    id,
    symbol,
    price,
    recorded_at,
    created_at,
    updated_at
FROM price_history
${WHERE_CLAUSE}
ORDER BY recorded_at ASC;
SQL

# 件数をカウント
RECORD_COUNT=$(wc -l < "$OUTPUT_FILE")
RECORD_COUNT=$((RECORD_COUNT - 1))  # ヘッダー行を除く

echo "✓ ${RECORD_COUNT}件のレコードをエクスポートしました"
echo "✓ ファイル: ${OUTPUT_FILE}"
echo ""

# 統計情報を表示
echo "--- 統計情報 ---"
sqlite3 database/database.sqlite <<SQL
SELECT
    '最小価格: ' || MIN(price) || '円' as stat
FROM price_history
${WHERE_CLAUSE}
UNION ALL
SELECT
    '最大価格: ' || MAX(price) || '円'
FROM price_history
${WHERE_CLAUSE}
UNION ALL
SELECT
    '平均価格: ' || ROUND(AVG(price), 2) || '円'
FROM price_history
${WHERE_CLAUSE}
UNION ALL
SELECT
    '最古記録: ' || MIN(recorded_at)
FROM price_history
${WHERE_CLAUSE}
UNION ALL
SELECT
    '最新記録: ' || MAX(recorded_at)
FROM price_history
${WHERE_CLAUSE};
SQL

echo ""
echo "========================================="
echo "使用例:"
echo "  ./export_price_history.sh XRP/JPY"
echo "  ./export_price_history.sh XRP/JPY '2025-11-28 00:00:00'"
echo "  ./export_price_history.sh XRP/JPY '2025-11-28 00:00:00' '2025-11-30 23:59:59'"
echo "========================================="
