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
   - 新しい戦略を追加する場合は`TradingStrategy`を継承

2. **Exchange Layer** (`app/Trading/Exchange/`)
   - `ExchangeClient.php`: 取引所クライアントのインターフェース
   - `PaperTradingClient.php`: ペーパートレード用（仮想取引）
   - `GMOCoinClient.php`: GMOコイン用（実取引）
   - `LiveTradingClient.php`: Binance用（実取引）

3. **Executor Layer** (`app/Trading/Executor/`)
   - `OrderExecutor.php`: 注文実行とポジション管理

### Database Tables

- **trading_settings**: 戦略の設定（通貨ペア、パラメータ等）
- **positions**: 現在・過去のポジション情報
- **trading_logs**: 全ての取引実行ログ

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

### トレーディング設定の登録

データベースに戦略を登録する例：

```php
use App\Models\TradingSettings;

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
3. データベースの`trading_settings`に戦略を登録
4. `php artisan trading:execute`でテスト

### 新しい取引所に対応

1. `app/Trading/Exchange/`に新しいクライアントクラスを作成
2. `ExchangeClient`インターフェースを実装
3. `config/trading.php`と`.env`に設定を追加
4. `TradingExecute`コマンドの`getExchangeClient()`メソッドに新しい取引所を追加

## Supported Exchanges

現在サポートされている取引所：

- **GMOコイン** (`EXCHANGE_NAME=gmo`)
  - 日本の仮想通貨取引所
  - APIドキュメント: https://api.coin.z.com/docs/
  - 対応通貨ペア: BTC/JPY, ETH/JPY, XRP/JPY, LTC/JPY, BCH/JPY等

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

## Architecture Notes

- **依存性注入**: `OrderExecutor`は`ExchangeClient`と`TradingStrategy`を受け取り、疎結合を実現
- **設定駆動**: 戦略パラメータはDBに保存し、コード変更なしで調整可能
- **モード切り替え**: 環境変数でペーパー/ライブを切り替え、同一コードベースで運用
