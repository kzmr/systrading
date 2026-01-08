# 仮想通貨自動トレーディングシステム

Laravel 12 + PHP 8.3 で構築された仮想通貨の自動トレーディングシステムです。

**現在運用中**: BTC/JPY RSI逆張り戦略（GMOコイン）

## 特徴

- ✅ **RSI逆張り戦略**（メイン）と**HighLowBreakout戦略**を実装
- ✅ **手数料トラッキング**: エントリー・決済時の手数料を自動記録
- ✅ ペーパートレード（仮想取引）とライブトレード（実取引）の両対応
- ✅ 1分ごとの自動実行
- ✅ 戦略の柔軟な切り替え（Strategy パターン）
- ✅ 複数の通貨ペア・複数戦略に対応
- ✅ 完全なログ記録とポジション管理

## セットアップ

### 1. 依存関係のインストール

```bash
composer install
```

### 2. 環境設定

```bash
cp .env.example .env
php artisan key:generate
```

### 3. データベースのセットアップ

```bash
php artisan migrate
php artisan db:seed --class=TradingSettingsSeeder
```

### 4. 環境変数の設定

`.env` ファイルを編集：

```bash
# ペーパートレード（仮想）で開始
TRADING_MODE=paper

# GMOコインでライブトレードに切り替える場合
# TRADING_MODE=live
# EXCHANGE_NAME=gmo
# EXCHANGE_API_KEY=your_gmo_api_key
# EXCHANGE_API_SECRET=your_gmo_api_secret

# Binanceでライブトレードに切り替える場合
# TRADING_MODE=live
# EXCHANGE_NAME=binance
# EXCHANGE_API_KEY=your_binance_api_key
# EXCHANGE_API_SECRET=your_binance_api_secret
```

## 使い方

### トレーディングコマンドを手動実行

```bash
php artisan trading:execute
```

### スケジューラーを起動（1分ごとに自動実行）

```bash
php artisan schedule:work
```

### 戦略を有効化

データベースで設定を有効にします：

```bash
php artisan tinker
```

```php
$setting = App\Models\TradingSettings::first();
$setting->is_active = true;
$setting->save();
```

または、SQLiteデータベースを直接編集：

```bash
sqlite3 database/database.sqlite
UPDATE trading_settings SET is_active = 1 WHERE id = 1;
.exit
```

### ログを確認

```bash
# Laravelログ
tail -f storage/logs/laravel.log

# DBログを確認
php artisan tinker
App\Models\TradingLog::latest()->get();
```

## アーキテクチャ

```
app/Trading/
├── Strategy/              # トレーディング戦略
│   ├── TradingStrategy.php          # 基底クラス
│   ├── RSIContrarianStrategy.php    # RSI逆張り戦略（メイン）
│   └── HighLowBreakoutStrategy.php  # 高値安値ブレイク戦略
├── Exchange/              # 取引所クライアント
│   ├── ExchangeClient.php
│   ├── PaperTradingClient.php  # ペーパートレード（手数料シミュレート）
│   ├── GMOCoinClient.php       # GMOコイン対応（手数料自動取得）
│   └── LiveTradingClient.php   # Binance対応
└── Executor/              # 注文実行・リスク管理
    └── OrderExecutor.php
```

## サポート取引所

- **GMOコイン** (`EXCHANGE_NAME=gmo`)
  - 日本の大手仮想通貨取引所
  - 対応通貨: BTC/JPY, ETH/JPY, XRP/JPY, LTC/JPY, BCH/JPY等
  - API取得: [GMOコイン会員ページ](https://coin.z.com/jp/)

- **Binance** (`EXCHANGE_NAME=binance`)
  - 世界最大の仮想通貨取引所
  - 対応通貨: BTC/USDT, ETH/USDT等
  - API取得: [Binance API Management](https://www.binance.com/en/my/settings/api-management)

## 新しい戦略の追加

1. `app/Trading/Strategy/` に新しいクラスを作成
2. `TradingStrategy` を継承
3. `analyze()` メソッドを実装
4. データベースに登録

例：

```php
namespace App\Trading\Strategy;

class MyCustomStrategy extends TradingStrategy
{
    public function analyze(array $marketData): array
    {
        // あなたの戦略ロジック
        return [
            'action' => 'buy', // 'buy', 'sell', 'hold'
            'quantity' => 0.01,
            'price' => $marketData['prices'][0],
        ];
    }
}
```

## 安全に関する注意

⚠️ **重要**: 必ずペーパートレードで十分にテストしてから、ライブトレードに移行してください。

- APIキーは `.env` ファイルに保存し、絶対にコミットしない
- 最初は少額から開始
- ストップロス・利益確定の設定を確認
- **手数料を考慮**: 純損益（手数料控除後）で収益性を判断
- ログを定期的にモニタリング（`./show_current_positions.sh`）

## 開発

テストの実行：

```bash
php artisan test
```

コードスタイルチェック：

```bash
./vendor/bin/pint
```

## ライセンス

MIT
