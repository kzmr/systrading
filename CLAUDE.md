# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

仮想通貨自動トレーディングシステム（Laravel 12 + PHP 8.3）

Laravelベースの自動トレーディングシステムで、ペーパートレード（仮想）とライブトレード（実取引）の両方に対応。1分ごとにArtisanコマンドが実行され、設定された戦略に基づいて自動売買を行います。

## Core Architecture

### 3層アーキテクチャ

1. **Strategy Layer** (`app/Trading/Strategy/`)
   - `TradingStrategy.php`: 戦略の基底クラス
   - `SimpleMovingAverageStrategy.php`: 移動平均線戦略の実装例
   - `HighLowBreakoutStrategy.php`: 高値安値ブレイクアウト戦略（現在運用中）
   - 新しい戦略を追加する場合は`TradingStrategy`を継承

2. **Exchange Layer** (`app/Trading/Exchange/`)
   - `ExchangeClient.php`: 取引所クライアントのインターフェース
   - `PaperTradingClient.php`: ペーパートレード用（仮想取引）
   - `GMOCoinClient.php`: GMOコイン用（実取引）
   - `LiveTradingClient.php`: Binance用（実取引）

3. **Executor Layer** (`app/Trading/Executor/`)
   - `OrderExecutor.php`: 注文実行とポジション管理
     - 複数ポジション管理（同一方向最大3つ）
     - 逆方向ブレイク時の一斉決済 + 即座に逆方向エントリー
     - スプレッドチェック（新規エントリー時のみ）

### Database Tables

- **trading_settings**: 戦略の設定（通貨ペア、パラメータ等）
- **positions**: 現在・過去のポジション情報
  - `side`: 'long' または 'short'
  - `status`: 'open' または 'closed'
  - `entry_price`, `exit_price`, `profit_loss`
- **trading_logs**: 全ての取引実行ログ

## Implemented Strategies

### HighLowBreakoutStrategy（高値安値ブレイクアウト戦略）

現在運用中の主力戦略。過去N本の価格レンジをブレイクした時にエントリー。

**パラメータ:**
```php
[
    'lookback_period' => 20,      // 過去20本（分）の価格データを参照
    'breakout_threshold' => 0.5,  // ブレイクアウト閾値（%）
]
```

**シグナル発生条件:**
- **買いシグナル**: 現在価格 > 過去20本の最高値 × 1.005
- **ショートシグナル**: 現在価格 < 過去20本の最安値 × 0.995

**動作フロー:**
1. **ロング保有中 + 高値ブレイク** → ロング追加（上限3まで）
2. **ロング保有中 + 安値ブレイク** → 全ロング決済 → ショート新規
3. **ショート保有中 + 安値ブレイク** → ショート追加（上限3まで）
4. **ショート保有中 + 高値ブレイク** → 全ショート決済 → ロング新規

**エントリー制限:**
- 新規エントリー時のみスプレッドチェック（max_spread: 300円）
- 同一方向ポジション数上限: 3
- 上限到達時は追加エントリーをスキップ

## Common Commands

### 開発・テスト

```bash
# データベースマイグレーション実行
php artisan migrate

# トレーディングコマンドを手動実行（テスト用）
php artisan trading:execute

# スケジューラーを起動（本番運用）
php artisan schedule:work

# データベースリセット（開発時）
php artisan migrate:fresh

# Laravelのキャッシュクリア
php artisan cache:clear
php artisan config:clear
```

### 監視・デバッグ用スクリプト

プロジェクトルートにある便利なシェルスクリプト：

```bash
# 現在のポジション状況を表示
./show_current_positions.sh [通貨ペア]
# 例: ./show_current_positions.sh XRP/JPY
# 出力: オープンポジション、損益、保有時間、最新のクローズ済みポジション等

# トレーディングログをファイルに保存
./show_trading_logs.sh [通貨ペア] [件数]
# 例: ./show_trading_logs.sh XRP/JPY 100
# 出力: trading_logs_YYYYMMDD.log（アクション別集計、エラーログ含む）

# リアルタイムログ監視（戦略分析詳細付き）
./tail_trading_logs.sh [通貨ペア]
# 例: ./tail_trading_logs.sh XRP/JPY
# 出力:
#   - 最新の戦略分析（現在価格、高値・安値、ブレイクアウト閾値）
#   - リアルタイム監視（新規ログと価格変動を1秒ごとに表示）
#   - 自動保存: trading_logs_live.log
# 停止: Ctrl+C
```

**スクリプトの出力例:**
```
--- 最新の戦略分析 ---
分析時刻: 2025-11-30 09:48:00
現在価格: 344.52円

【過去20本のレンジ】
  最高値: 344.82円
  最安値: 343.924円
  レンジ幅: 0.896円

【ブレイクアウト閾値】
  買い閾値: 346.54円 (あと2.02円 / 0.58%)
  売り閾値: 342.20円 (あと2.32円 / 0.67%)
```

### トレーディング設定の登録

データベースに戦略を登録する例：

```php
use App\Models\TradingSettings;

// HighLowBreakoutStrategy の例
TradingSettings::create([
    'name' => 'XRP高値安値ブレイク戦略',
    'symbol' => 'XRP/JPY',
    'strategy' => 'App\\Trading\\Strategy\\HighLowBreakoutStrategy',
    'parameters' => [
        'lookback_period' => 20,
        'breakout_threshold' => 0.5,
    ],
    'is_active' => true,
]);

// SimpleMovingAverageStrategy の例
TradingSettings::create([
    'name' => 'BTC移動平均戦略',
    'symbol' => 'BTC/USDT',
    'strategy' => 'App\\Trading\\Strategy\\SimpleMovingAverageStrategy',
    'parameters' => [
        'short_period' => 5,
        'long_period' => 20,
        'trade_size' => 0.01,
    ],
    'is_active' => true,
]);
```

### 環境切り替え

.envファイルで`TRADING_MODE`と`EXCHANGE_NAME`を変更：

```bash
# ペーパートレード（安全・デフォルト）
TRADING_MODE=paper

# ライブトレード（GMOコイン）
TRADING_MODE=live
EXCHANGE_NAME=gmo
EXCHANGE_BASE_URL=https://api.coin.z.com
EXCHANGE_API_KEY=your_gmo_api_key
EXCHANGE_API_SECRET=your_gmo_api_secret

# ライブトレード（Binance）
TRADING_MODE=live
EXCHANGE_NAME=binance
EXCHANGE_BASE_URL=https://api.binance.com
EXCHANGE_API_KEY=your_binance_api_key
EXCHANGE_API_SECRET=your_binance_api_secret
```

## Development Workflow

### 新しい戦略を追加

1. `app/Trading/Strategy/`に新しいクラスを作成
2. `TradingStrategy`を継承し、`analyze()`メソッドを実装
3. シグナルは以下の形式で返す：
   ```php
   return [
       'action' => 'buy|sell|short|hold',
       'quantity' => 0.01,
       'price' => null, // null = 成行注文
   ];
   ```
4. データベースの`trading_settings`に戦略を登録
5. `php artisan trading:execute`でテスト

### 新しい取引所に対応

1. `app/Trading/Exchange/`に新しいクライアントクラスを作成
2. `ExchangeClient`インターフェースを実装
3. `config/trading.php`と`.env`に設定を追加
4. `TradingExecute`コマンドの`getExchangeClient()`メソッドに新しい取引所を追加

## Position Management

### 複数ポジション管理の仕様

**OrderExecutor.php** が実装する重要な機能：

1. **ポジション重複（ピラミッディング）**
   - 同一方向に最大3ポジションまで保有可能
   - トレンドフォロー型の戦略に最適

2. **逆方向ブレイク時の処理**
   ```
   ロング3つ保有中 → 安値ブレイク発生
   → 全ロング一斉決済（3つとも）
   → 即座にショート1つ新規エントリー
   ```

3. **同方向ブレイク時の処理**
   ```
   ロング2つ保有中 → 高値ブレイク発生
   → ロング1つ追加（計3つ）

   ロング3つ保有中 → 高値ブレイク発生
   → エントリー見送り（上限到達）
   ```

4. **スプレッドチェック**
   - **新規エントリー時のみ**スプレッドをチェック
   - 決済時はスプレッドチェックなし（即座に執行）
   - デフォルト上限: 300円（`config/trading.php`で設定可能）

### 損益計算

- **ロングポジション**: `(exit_price - entry_price) × quantity`
- **ショートポジション**: `(entry_price - exit_price) × quantity`

## Supported Exchanges

現在サポートされている取引所：

- **GMOコイン** (`EXCHANGE_NAME=gmo`)
  - 日本の仮想通貨取引所
  - APIドキュメント: https://api.coin.z.com/docs/
  - 対応通貨ペア: BTC/JPY, ETH/JPY, XRP/JPY, LTC/JPY, BCH/JPY等
  - 現在運用中: XRP/JPY（ペーパートレード）

- **Binance** (`EXCHANGE_NAME=binance`)
  - 世界最大の仮想通貨取引所
  - APIドキュメント: https://binance-docs.github.io/apidocs/
  - 対応通貨ペア: BTC/USDT, ETH/USDT等

## Security Guidelines

1. **APIキーの管理**
   - 絶対に`.env`ファイルをコミットしない
   - `.env.example`にはダミー値のみ記載
   - 本番環境では環境変数を使用

2. **ライブトレードの前に**
   - ペーパートレードで十分にテスト
   - 少額から開始
   - ストップロス・利益確定の設定を確認

3. **ログとモニタリング**
   - `storage/logs/laravel.log`で実行ログを確認
   - `trading_logs`テーブルで全取引履歴を追跡
   - エラー発生時は即座に`is_active=false`に設定
   - 監視スクリプト（`tail_trading_logs.sh`）でリアルタイム監視

## Architecture Notes

- **依存性注入**: `OrderExecutor`は`ExchangeClient`と`TradingStrategy`を受け取り、疎結合を実現
- **設定駆動**: 戦略パラメータはDBに保存し、コード変更なしで調整可能
- **モード切り替え**: 環境変数でペーパー/ライブを切り替え、同一コードベースで運用
- **複数ポジション管理**: トレンドフォロー戦略に対応した柔軟なポジション管理
- **戦略独立性**: StrategyレイヤーはExecutorレイヤーから完全に独立し、再利用可能

## Troubleshooting

### よくある問題

1. **スプレッドが広すぎてエントリーできない**
   - `config/trading.php`の`max_spread`を調整
   - または`.env`で`TRADING_MAX_SPREAD=500`のように設定

2. **ポジションが意図せず増えすぎる**
   - 複数ポジション機能により最大3つまで保有される仕様
   - `OrderExecutor.php`の上限値（現在3）を変更可能

3. **GMOコインAPIエラー（ERR-5201）**
   - メンテナンス中のエラー
   - 自動的にリトライされるため、放置して問題なし

4. **戦略分析ログが見つからない**
   - `storage/logs/laravel.log`を確認
   - `tail_trading_logs.sh`で自動的に抽出される

## Testing

### 戦略のバックテスト手順

1. 過去データを用意（GMOコインAPIから取得可能）
2. `TRADING_MODE=paper`でペーパートレード実行
3. `show_current_positions.sh`で結果確認
4. `show_trading_logs.sh`でログ分析

### 検証項目

- [ ] ブレイクアウトシグナルの正確性
- [ ] スプレッドチェックの動作
- [ ] 複数ポジション管理の正常動作
- [ ] 逆方向ブレイク時の一斉決済
- [ ] 損益計算の正確性（long/short両方）
