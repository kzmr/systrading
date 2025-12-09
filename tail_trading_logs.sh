#!/bin/bash

# 複数戦略対応のリアルタイムトレーディングログ監視スクリプト
# 使い方:
#   ./tail_trading_logs.sh          # 全戦略を監視
#   ./tail_trading_logs.sh 5        # trading_settings ID=5 のみ
#   ./tail_trading_logs.sh XRP/JPY  # 通貨ペア指定（後方互換）

SETTING_ID="$1"
OUTPUT_FILE="trading_logs_live.log"
LARAVEL_LOG="storage/logs/laravel.log"

echo "========================================="
echo "  トレーディングログ リアルタイム監視"
echo "========================================="

# アクティブな戦略一覧を取得
echo "--- アクティブな戦略 ---"
sqlite3 -header -column database/database.sqlite <<SQL
SELECT
    id as 'ID',
    symbol as '通貨ペア',
    name as '戦略名'
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
        SYMBOL_FILTER="symbol = '${SYMBOL}'"
        echo "監視対象: ID=${SETTING_ID} (${SYMBOL})"
    else
        # 文字列の場合は通貨ペア指定（後方互換）
        SYMBOL="$SETTING_ID"
        FILTER="AND symbol = '${SYMBOL}'"
        SYMBOL_FILTER="symbol = '${SYMBOL}'"
        echo "監視対象: ${SYMBOL}"
    fi
else
    FILTER=""
    SYMBOL_FILTER="1=1"
    echo "監視対象: 全戦略"
fi

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
    symbol as '通貨ペア',
    action as 'アクション',
    message as 'メッセージ'
FROM trading_logs
WHERE 1=1 ${FILTER}
ORDER BY executed_at DESC
LIMIT 10;
SQL

echo ""
echo "--- 最新の戦略分析 ---"

# 各戦略の最新分析情報を表示
SYMBOLS=$(sqlite3 database/database.sqlite "SELECT DISTINCT symbol FROM trading_settings WHERE is_active = 1 AND ${SYMBOL_FILTER}")

for SYM in $SYMBOLS; do
    # シンボルに応じたログパターンを検索
    SYM_BASE=$(echo "$SYM" | cut -d'/' -f1)  # XRP/JPY -> XRP, BTC/JPY -> BTC

    LATEST_ANALYSIS=$(grep "High-Low Breakout Analysis" "$LARAVEL_LOG" | grep "\"symbol\":\"${SYM}\"" | tail -1)

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

        echo "【${SYM}】"
        echo "分析時刻: ${TIMESTAMP}"
        echo "現在価格: ${CURRENT_PRICE}円"
        echo ""
        echo "  過去${LOOKBACK}本のレンジ:"
        echo "    最高値: ${HIGHEST_HIGH}円"
        echo "    最安値: ${LOWEST_LOW}円"
        echo "    レンジ幅: $(echo "$HIGHEST_HIGH - $LOWEST_LOW" | bc)円"
        echo ""
        echo "  ブレイクアウト閾値:"
        echo "    買い閾値: ${BUY_THRESHOLD}円 (あと${BUY_DISTANCE}円 / ${BUY_PERCENT}%)"
        echo "    売り閾値: ${SELL_THRESHOLD}円 (あと${SELL_DISTANCE}円 / ${SELL_PERCENT}%)"
        echo ""
    else
        echo "【${SYM}】戦略分析情報が見つかりません"
        echo ""
    fi
done

echo ""
echo "--- 新しいログを監視中... ---"
echo ""

# 最後に表示したログのIDを取得
LAST_ID=$(sqlite3 database/database.sqlite "SELECT COALESCE(MAX(id), 0) FROM trading_logs WHERE 1=1 ${FILTER}")

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
    symbol,
    action,
    quantity,
    price,
    message
FROM trading_logs
WHERE id > ${LAST_ID} ${FILTER}
ORDER BY id ASC;
SQL
)

    if [ -n "$NEW_LOGS" ]; then
        while IFS=$'\t' read -r id executed_at symbol action quantity price message; do
            echo "[$(date '+%H:%M:%S')] ${executed_at} | ${symbol} | ${action} | ${message}" | tee -a "$OUTPUT_FILE"
            LAST_ID=$id
        done <<< "$NEW_LOGS"
    fi

    # 2. Laravelログの戦略分析情報をチェック
    if [ -f "$LARAVEL_LOG" ]; then
        CURRENT_LOG_LINE=$(wc -l < "$LARAVEL_LOG")

        if [ "$CURRENT_LOG_LINE" -gt "$LAST_LOG_LINE" ]; then
            # 新しい行のみを取得
            NEW_LINES=$((CURRENT_LOG_LINE - LAST_LOG_LINE))

            # 監視対象のシンボルごとに分析情報をチェック
            for SYM in $SYMBOLS; do
                NEW_ANALYSIS=$(tail -"$NEW_LINES" "$LARAVEL_LOG" | grep "High-Low Breakout Analysis" | grep "\"symbol\":\"${SYM}\"" | tail -1)

                if [ -n "$NEW_ANALYSIS" ]; then
                    TIMESTAMP=$(echo "$NEW_ANALYSIS" | grep -oE '\[.*?\]' | head -1 | tr -d '[]')
                    JSON_DATA=$(echo "$NEW_ANALYSIS" | grep -oE '\{.*\}')

                    CURRENT_PRICE=$(echo "$JSON_DATA" | jq -r '.current_price')
                    BUY_THRESHOLD=$(echo "$JSON_DATA" | jq -r '.buy_threshold')
                    SELL_THRESHOLD=$(echo "$JSON_DATA" | jq -r '.sell_threshold')

                    BUY_DISTANCE=$(printf "%.2f" $(echo "$BUY_THRESHOLD - $CURRENT_PRICE" | bc))
                    SELL_DISTANCE=$(printf "%.2f" $(echo "$CURRENT_PRICE - $SELL_THRESHOLD" | bc))

                    echo "[$(date '+%H:%M:%S')] 分析 | ${SYM} | 価格:${CURRENT_PRICE}円 | 買い閾値まであと${BUY_DISTANCE}円 | 売り閾値まであと${SELL_DISTANCE}円" | tee -a "$OUTPUT_FILE"
                fi
            done

            LAST_LOG_LINE=$CURRENT_LOG_LINE
        fi
    fi

    sleep 1
done
