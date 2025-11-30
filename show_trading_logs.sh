#!/bin/bash

# 出力ファイル名
OUTPUT_FILE="trading_logs_$(date '+%Y%m%d').log"

# 通貨ペアを引数から取得（デフォルトはXRP/JPY）
SYMBOL="${1:-XRP/JPY}"

# ログ件数を引数から取得（デフォルトは100件）
LIMIT="${2:-100}"

echo "=========================================" | tee "$OUTPUT_FILE"
echo "  トレーディング実行ログ" | tee -a "$OUTPUT_FILE"
echo "=========================================" | tee -a "$OUTPUT_FILE"
echo "通貨ペア: ${SYMBOL}" | tee -a "$OUTPUT_FILE"
echo "抽出件数: ${LIMIT}件" | tee -a "$OUTPUT_FILE"
echo "出力ファイル: ${OUTPUT_FILE}" | tee -a "$OUTPUT_FILE"
echo "出力日時: $(date '+%Y-%m-%d %H:%M:%S')" | tee -a "$OUTPUT_FILE"
echo "" | tee -a "$OUTPUT_FILE"

echo "--- 最新${LIMIT}件の実行ログ ---" | tee -a "$OUTPUT_FILE"
sqlite3 -header -column database/database.sqlite <<SQL | tee -a "$OUTPUT_FILE"
SELECT
    id as 'ID',
    symbol as '通貨',
    action as 'アクション',
    quantity as '数量',
    price as '価格',
    message as 'メッセージ',
    datetime(executed_at, 'localtime') as '実行日時'
FROM trading_logs
WHERE symbol = '${SYMBOL}'
ORDER BY executed_at DESC
LIMIT ${LIMIT};
SQL

echo "" | tee -a "$OUTPUT_FILE"
echo "--- アクション別集計 ---" | tee -a "$OUTPUT_FILE"
sqlite3 -header -column database/database.sqlite <<SQL | tee -a "$OUTPUT_FILE"
SELECT
    action as 'アクション',
    COUNT(*) as '回数',
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM trading_logs WHERE symbol = '${SYMBOL}'), 2) || '%' as '割合'
FROM trading_logs
WHERE symbol = '${SYMBOL}'
GROUP BY action
ORDER BY COUNT(*) DESC;
SQL

echo "" | tee -a "$OUTPUT_FILE"
echo "--- 最近のエラーログ ---" | tee -a "$OUTPUT_FILE"
sqlite3 -header -column database/database.sqlite <<SQL | tee -a "$OUTPUT_FILE"
SELECT
    id as 'ID',
    symbol as '通貨',
    message as 'エラーメッセージ',
    datetime(executed_at, 'localtime') as '発生日時'
FROM trading_logs
WHERE symbol = '${SYMBOL}' AND action = 'error'
ORDER BY executed_at DESC
LIMIT 20;
SQL

echo "" | tee -a "$OUTPUT_FILE"
echo "=========================================" | tee -a "$OUTPUT_FILE"
echo "ログファイルに保存しました: ${OUTPUT_FILE}" | tee -a "$OUTPUT_FILE"
echo "=========================================" | tee -a "$OUTPUT_FILE"
