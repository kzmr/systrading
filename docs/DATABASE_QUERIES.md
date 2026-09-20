# データベースクエリ集

SQLiteデータベースに直接アクセスして、取引データを確認する方法をまとめました。

## データベースへの接続

```bash
# SQLiteクライアントで接続
sqlite3 database/database.sqlite

# 接続後、以下のコマンドで見やすく表示
.headers on
.mode column
```

## よく使うクエリ

### 1. 最新の取引ログを確認

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 6 10 10 30 20
SELECT id, symbol, action, quantity, price, message, executed_at
FROM trading_logs
ORDER BY executed_at DESC
LIMIT 20;
EOF
```

### 2. 今日の取引ログのみ表示

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT id, symbol, action, quantity, price, message, executed_at
FROM trading_logs
WHERE DATE(executed_at) = DATE('now')
ORDER BY executed_at DESC;
EOF
```

### 3. アクション別の集計

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT action, COUNT(*) as count, symbol
FROM trading_logs
GROUP BY action, symbol
ORDER BY count DESC;
EOF
```

### 4. 実際に取引が発生したログのみ（buy/sell）

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT id, symbol, action, quantity, price, executed_at
FROM trading_logs
WHERE action IN ('buy', 'sell')
ORDER BY executed_at DESC;
EOF
```

### 5. 有効な取引設定を確認

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT id, name, symbol, is_active, parameters
FROM trading_settings;
EOF
```

### 6. オープンポジションを確認

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 6 10 12 12 8 20
SELECT id, symbol, side, quantity, entry_price, exit_price, status, opened_at
FROM positions
WHERE status = 'open'
ORDER BY opened_at DESC;
EOF
```

### 7. クローズされたポジション（損益付き）

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
.width 3 8 6 10 12 12 12 20
SELECT id, symbol, side, quantity, entry_price, exit_price, profit_loss, closed_at
FROM positions
WHERE status = 'closed'
ORDER BY closed_at DESC
LIMIT 20;
EOF
```

### 8. 時間帯別の取引頻度

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT
    strftime('%H', executed_at) as hour,
    action,
    COUNT(*) as count
FROM trading_logs
GROUP BY hour, action
ORDER BY hour, action;
EOF
```

### 9. エラーログのみ表示

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT id, symbol, action, message, executed_at
FROM trading_logs
WHERE action = 'error' OR message LIKE '%エラー%'
ORDER BY executed_at DESC;
EOF
```

### 10. 損益サマリー（全ポジション）

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT
    symbol,
    COUNT(*) as total_trades,
    SUM(profit_loss) as total_profit,
    AVG(profit_loss) as avg_profit,
    MIN(profit_loss) as min_profit,
    MAX(profit_loss) as max_profit
FROM positions
WHERE status = 'closed'
GROUP BY symbol;
EOF
```

### 11. 手数料を含む純損益サマリー

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT
    symbol,
    COUNT(*) as trades,
    ROUND(SUM(profit_loss), 2) as gross_profit,
    ROUND(SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as total_fees,
    ROUND(SUM(profit_loss) - SUM(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as net_profit,
    ROUND(AVG(profit_loss - (IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0))), 2) as avg_net_profit
FROM positions
WHERE status = 'closed'
GROUP BY symbol;
EOF
```

### 12. 本日の手数料を含む取引詳細

```bash
sqlite3 database/database.sqlite << 'EOF'
.headers on
.mode column
SELECT
    id,
    symbol,
    side,
    ROUND(profit_loss, 2) as pl,
    ROUND(IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0), 2) as fee,
    ROUND(profit_loss - (IFNULL(entry_fee, 0) + IFNULL(exit_fee, 0)), 2) as net_pl,
    datetime(closed_at, 'localtime') as closed
FROM positions
WHERE status = 'closed'
AND DATE(closed_at) = DATE('now', 'localtime')
ORDER BY closed_at DESC;
EOF
```

### 13. データ収集の健全性チェック

売買が停止していてもデータ収集は動き続ける。欠損があればAPI障害やスケジューラ停止を疑う。

```bash
sqlite3 database/database.sqlite "
SELECT '価格' AS 系統, COUNT(*) AS 直近24h FROM price_history
  WHERE symbol='BTC/JPY' AND recorded_at >= datetime('now','-24 hours')
UNION ALL SELECT '板情報', COUNT(*) FROM order_book_snapshots
  WHERE symbol='BTC/JPY' AND recorded_at >= datetime('now','-24 hours')
UNION ALL SELECT '市場横断', COUNT(*) FROM cross_market_snapshots
  WHERE recorded_at >= datetime('now','-24 hours');"
```

毎分収集なので理想値は 1440。GMOのメンテナンス時間帯は価格と板情報が欠ける。

### 14. 日本プレミアム・取引所間価格差

```bash
sqlite3 database/database.sqlite "
SELECT recorded_at, gmo_mid, btc_usd, usd_jpy,
       ROUND(premium_percent, 4) AS プレミアム,
       fx_age_seconds AS FX鮮度秒
FROM cross_market_snapshots
WHERE fx_age_seconds <= 900        -- FX取引時間内のみ
ORDER BY recorded_at DESC LIMIT 20;"
```

**`fx_age_seconds <= 900` のフィルタは必須。** FX市場は土日に閉じるため、
古い為替レートで計算したプレミアムはBTCの値動きを反映した偽の値になる
（振れ幅が 0.20% → 0.40% と約2倍に膨らむ）。

### 15. S&P500セッション（SpxReversalStrategyのシグナル源）

```bash
sqlite3 database/database.sqlite "
SELECT session_date,
       ROUND(session_move_percent, 3) AS 変動率,
       CASE WHEN session_move_percent <= -0.40 THEN '該当' ELSE '' END AS 判定,
       bar_count, is_complete
FROM spx_sessions ORDER BY session_date DESC LIMIT 10;"
```

### 16. 撤退基準の現況

```bash
# コマンド経由（停止せず判定のみ）
php artisan strategy:guard --dry-run

# 直接確認
sqlite3 database/database.sqlite "
SELECT ts.name,
       COUNT(*) AS 取引数,
       ROUND(SUM(p.profit_loss - COALESCE(p.entry_fee,0) - COALESCE(p.exit_fee,0)), 1) AS 純損益
FROM positions p JOIN trading_settings ts ON ts.id = p.trading_settings_id
WHERE p.status='closed' AND p.profit_loss IS NOT NULL
GROUP BY ts.id;"
```

### 17. 検証用データのエクスポート

```bash
# 価格履歴
sqlite3 -header -csv database/database.sqlite \
  "SELECT id,symbol,price,recorded_at FROM price_history
   WHERE symbol='BTC/JPY' ORDER BY recorded_at;" > storage/backtest/btc.csv

# 市場横断（取引所間価格差の検証用）
sqlite3 -header -csv database/database.sqlite \
  "SELECT recorded_at, gmo_mid, bitflyer_mid, coincheck_mid, bitbank_mid,
          btc_usd, usd_jpy, fx_age_seconds, premium_percent
   FROM cross_market_snapshots ORDER BY recorded_at;" > storage/backtest/premium.csv
```


## インタラクティブモード

SQLiteに接続してインタラクティブにクエリを実行：

```bash
sqlite3 database/database.sqlite
```

接続後、以下のコマンドが使えます：

```sql
-- テーブル一覧
.tables

-- テーブル構造を確認
.schema trading_logs
.schema positions
.schema trading_settings

-- 見やすい表示設定
.headers on
.mode column

-- クエリ実行例
SELECT * FROM trading_logs ORDER BY executed_at DESC LIMIT 5;

-- 終了
.exit
```

## リアルタイム監視

新しいログをリアルタイムで監視：

```bash
# 5秒ごとに最新ログを表示
watch -n 5 "sqlite3 database/database.sqlite 'SELECT * FROM trading_logs ORDER BY executed_at DESC LIMIT 5;'"
```

または、Laravelのログファイルを監視：

```bash
tail -f storage/logs/laravel.log
```

## データのバックアップ

```bash
# データベース全体をバックアップ
cp database/database.sqlite database/database.backup.$(date +%Y%m%d_%H%M%S).sqlite

# SQLダンプを作成
sqlite3 database/database.sqlite .dump > backup.sql
```

## トラブルシューティング

### データベースがロックされている場合

```bash
# データベースのロックを確認
lsof database/database.sqlite

# プロセスを確認して必要に応じて停止
```

### データベースの整合性チェック

```bash
sqlite3 database/database.sqlite "PRAGMA integrity_check;"
```

### テーブルの最適化

```bash
sqlite3 database/database.sqlite "VACUUM;"
```
