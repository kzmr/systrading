#!/bin/bash

# リアルタイムでトレーディングログを監視するスクリプト（戦略分析詳細付き）
# 使い方: ./tail_trading_logs.sh [通貨ペア]

SYMBOL="${1:-XRP/JPY}"
OUTPUT_FILE="trading_logs_live.log"
LARAVEL_LOG="storage/logs/laravel.log"

echo "========================================="
echo "  トレーディングログ リアルタイム監視"
echo "========================================="
echo "通貨ペア: ${SYMBOL}"
echo "出力ファイル: ${OUTPUT_FILE}"
echo "開始時刻: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "停止するには: Ctrl+C"
echo "========================================="
echo ""

# 既存のログを表示
echo "--- 最新10件のログ ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    datetime(executed_at, 'localtime') as '実行日時',
    action as 'アクション',
    message as 'メッセージ'
FROM trading_logs
WHERE symbol = '${SYMBOL}'
ORDER BY executed_at DESC
LIMIT 10;
SQL

echo ""
echo "--- 最新の戦略分析 ---"
# Laravelログから最新の分析情報を取得
LATEST_ANALYSIS=$(grep "High-Low Breakout Analysis" "$LARAVEL_LOG" | tail -1)
if [ -n "$LATEST_ANALYSIS" ]; then
    TIMESTAMP=$(echo "$LATEST_ANALYSIS" | grep -oE '\[.*?\]' | head -1 | tr -d '[]')
    JSON_DATA=$(echo "$LATEST_ANALYSIS" | grep -oE '\{.*\}')

    CURRENT_PRICE=$(echo "$JSON_DATA" | jq -r '.current_price')
    HIGHEST_HIGH=$(echo "$JSON_DATA" | jq -r '.highest_high')
    LOWEST_LOW=$(echo "$JSON_DATA" | jq -r '.lowest_low')
    BUY_THRESHOLD=$(printf "%.2f" $(echo "$JSON_DATA" | jq -r '.buy_threshold'))
    SELL_THRESHOLD=$(printf "%.2f" $(echo "$JSON_DATA" | jq -r '.sell_threshold'))
    LOOKBACK=$(echo "$JSON_DATA" | jq -r '.lookback_period')

    # ブレイクアウトまでの距離を計算
    BUY_DISTANCE=$(printf "%.2f" $(echo "$BUY_THRESHOLD - $CURRENT_PRICE" | bc))
    SELL_DISTANCE=$(printf "%.2f" $(echo "$CURRENT_PRICE - $SELL_THRESHOLD" | bc))
    BUY_PERCENT=$(printf "%.2f" $(echo "scale=4; ($BUY_THRESHOLD - $CURRENT_PRICE) / $CURRENT_PRICE * 100" | bc))
    SELL_PERCENT=$(printf "%.2f" $(echo "scale=4; ($CURRENT_PRICE - $SELL_THRESHOLD) / $CURRENT_PRICE * 100" | bc))

    echo "分析時刻: ${TIMESTAMP}"
    echo "現在価格: ${CURRENT_PRICE}円"
    echo ""
    echo "【過去${LOOKBACK}本のレンジ】"
    echo "  最高値: ${HIGHEST_HIGH}円"
    echo "  最安値: ${LOWEST_LOW}円"
    echo "  レンジ幅: $(echo "$HIGHEST_HIGH - $LOWEST_LOW" | bc)円"
    echo ""
    echo "【ブレイクアウト閾値】"
    echo "  買い閾値: ${BUY_THRESHOLD}円 (あと${BUY_DISTANCE}円 / ${BUY_PERCENT}%)"
    echo "  売り閾値: ${SELL_THRESHOLD}円 (あと${SELL_DISTANCE}円 / ${SELL_PERCENT}%)"
    echo ""
else
    echo "戦略分析情報が見つかりません"
fi

echo ""
echo "--- 新しいログを監視中... ---"
echo ""

# 最後に表示したログのIDを取得
LAST_ID=$(sqlite3 database/database.sqlite "SELECT MAX(id) FROM trading_logs WHERE symbol = '${SYMBOL}'")

# Laravelログの現在行数を取得
if [ -f "$LARAVEL_LOG" ]; then
    LAST_LOG_LINE=$(wc -l < "$LARAVEL_LOG")
else
    LAST_LOG_LINE=0
fi

# 1秒ごとに新しいログをチェック
while true; do
    # 1. trading_logsテーブルの新規ログをチェック
    NEW_LOGS=$(sqlite3 -separator $'\t' database/database.sqlite <<SQL
SELECT
    id,
    datetime(executed_at, 'localtime'),
    action,
    quantity,
    price,
    message
FROM trading_logs
WHERE symbol = '${SYMBOL}' AND id > ${LAST_ID}
ORDER BY id ASC;
SQL
)

    if [ -n "$NEW_LOGS" ]; then
        while IFS=$'\t' read -r id executed_at action quantity price message; do
            echo "[$(date '+%H:%M:%S')] ${executed_at} | ${action} | ${message}" | tee -a "$OUTPUT_FILE"
            LAST_ID=$id
        done <<< "$NEW_LOGS"
    fi

    # 2. Laravelログの戦略分析情報をチェック
    if [ -f "$LARAVEL_LOG" ]; then
        CURRENT_LOG_LINE=$(wc -l < "$LARAVEL_LOG")

        if [ "$CURRENT_LOG_LINE" -gt "$LAST_LOG_LINE" ]; then
            # 新しい行のみを取得
            NEW_LINES=$((CURRENT_LOG_LINE - LAST_LOG_LINE))
            NEW_ANALYSIS=$(tail -"$NEW_LINES" "$LARAVEL_LOG" | grep "High-Low Breakout Analysis" | tail -1)

            if [ -n "$NEW_ANALYSIS" ]; then
                TIMESTAMP=$(echo "$NEW_ANALYSIS" | grep -oE '\[.*?\]' | head -1 | tr -d '[]')
                JSON_DATA=$(echo "$NEW_ANALYSIS" | grep -oE '\{.*\}')

                CURRENT_PRICE=$(echo "$JSON_DATA" | jq -r '.current_price')
                BUY_THRESHOLD=$(echo "$JSON_DATA" | jq -r '.buy_threshold')
                SELL_THRESHOLD=$(echo "$JSON_DATA" | jq -r '.sell_threshold')

                BUY_DISTANCE=$(printf "%.2f" $(echo "$BUY_THRESHOLD - $CURRENT_PRICE" | bc))
                SELL_DISTANCE=$(printf "%.2f" $(echo "$CURRENT_PRICE - $SELL_THRESHOLD" | bc))

                echo "[$(date '+%H:%M:%S')] 分析 | 価格:${CURRENT_PRICE}円 | 買い閾値まであと${BUY_DISTANCE}円 | 売り閾値まであと${SELL_DISTANCE}円" | tee -a "$OUTPUT_FILE"
            fi

            LAST_LOG_LINE=$CURRENT_LOG_LINE
        fi
    fi

    sleep 1
done
