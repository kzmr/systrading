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
