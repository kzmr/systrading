# XRP/JPY Automated Trading System

<p align="center">
  <img src="Gemini_Generated_Image_euy57heuy57heuy5.png" alt="Trading System Overview" width="800">
</p>

<p align="center">
  <strong>Laravel 12 + PHP 8.3 ベースの仮想通貨自動トレーディングシステム</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-red" alt="Laravel 12">
  <img src="https://img.shields.io/badge/PHP-8.3-blue" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/Strategy-HighLowBreakout-green" alt="Strategy">
  <img src="https://img.shields.io/badge/Mode-PaperTrading-yellow" alt="Mode">
</p>

---

## 📊 概要

**XRP/JPY HighLowBreakout戦略**を実装した自動トレーディングシステム。GMOコインの実データを使用したペーパートレード（仮想取引）に対応し、1分ごとに戦略を実行して自動売買を行います。

### 主な特徴

- ✅ **高度なリスク管理**: 3層防御（固定損切り・トレーリングストップ・逆方向ブレイク決済）
- ✅ **バックテスト最適化済み**: トレーリングストップを0.5%に最適化（総損益101%向上）
- ✅ **マルチポジション対応**: 同一方向に最大3ポジションまで保有可能
- ✅ **完全自動運用**: 1分ごとの自動実行・自動決済
- ✅ **価格履歴記録**: 全取引データと価格履歴をデータベースに保存
- ✅ **安全なテスト環境**: ペーパートレードで実資金リスクなし

---

## 🎯 トレーディング戦略

### HighLowBreakout戦略

直近20本の高値・安値をブレイクした際にエントリーし、トレーリングストップで利益を伸ばす**トレンドフォロー型戦略**。

#### エントリー条件

**ロングエントリー（買い）**
- 直近20本の高値を**0.5%以上**ブレイクアウト
- スプレッドが現在価格の**0.1%以内**
- 同一方向のポジション数が3つ未満

**ショートエントリー（売り）**
- 直近20本の安値を**0.5%以上**ブレイクダウン
- スプレッドが現在価格の**0.1%以内**
- 同一方向のポジション数が3つ未満

#### エグジット条件（3段階リスク管理）

1. **固定損切り（最終防衛ライン）**
   - ロング: エントリー価格の **-1%** で強制決済
   - ショート: エントリー価格の **+1%** で強制決済

2. **トレーリングストップ（利益確定）** ⭐ **最適化済み**
   - 初期設定: エントリー価格 ± 1.5%
   - 動的更新: 直近20本の安値/高値 - **0.5%**
   - 更新条件: 有利な方向のみ（ストップを引き上げのみ）
   - バックテスト結果: 総損益が**101%向上**

3. **逆方向ブレイク時の強制決済**
   - ロング保有中に安値ブレイクダウン → 全ロング決済 → ショート新規
   - ショート保有中に高値ブレイクアウト → 全ショート決済 → ロング新規

---

## 🏗️ アーキテクチャ

### 3層設計

```
┌─────────────────────────────────────────────────┐
│   Strategy Layer (戦略層)                        │
│   - HighLowBreakoutStrategy.php                 │
│   - TradingStrategy (基底クラス)                 │
└─────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────┐
│   Executor Layer (実行層)                        │
│   - OrderExecutor.php                           │
│   - エントリー/エグジットロジック                  │
│   - リスク管理                                   │
└─────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────┐
│   Exchange Layer (取引所層)                      │
│   - PaperTradingClient.php (ペーパートレード)     │
│   - GMOCoinClient.php (実取引)                   │
│   - LiveTradingClient.php (Binance)             │
└─────────────────────────────────────────────────┘
```

### データベース

- **positions**: ポジション情報（現在・過去）
- **trading_logs**: 全取引実行ログ
- **price_history**: 価格履歴（1分足）

---

## 🚀 クイックスタート

### 1. 環境構築

```bash
# リポジトリクローン
git clone <repository-url>
cd systrading

# 依存関係インストール
composer install

# 環境ファイル設定
cp .env.example .env

# アプリケーションキー生成
php artisan key:generate

# データベースマイグレーション
php artisan migrate
```

### 2. 設定ファイル編集

`.env` ファイルで以下を設定：

```bash
# トレーディングモード
TRADING_MODE=paper  # paper: ペーパートレード, live: 実取引

# 取引所設定（GMOコイン）
EXCHANGE_NAME=gmo
EXCHANGE_BASE_URL=https://api.coin.z.com
EXCHANGE_API_KEY=your_api_key_here
EXCHANGE_API_SECRET=your_api_secret_here

# トレーディングパラメータ
TRADE_SIZE=0.01
MAX_POSITIONS=3
TRAILING_STOP_OFFSET_PERCENT=0.5  # 最適化済み
```

### 3. システム起動

```bash
# 自動トレーディング開始（1分ごとに実行）
php artisan schedule:work

# 別ターミナルで現在のポジション確認
./show_current_positions.sh
```

### 4. システム停止

```bash
pkill -f schedule:work
```

---

## 📈 パフォーマンス

### バックテスト結果（2,713件の価格データ）

| 指標 | 0.3%オフセット（旧） | **0.5%オフセット（最適化）** |
|------|-------------------|---------------------------|
| 総損益 | 0.3725円 | **0.7498円** (+101%) ⭐ |
| 勝率 | 57.14% | **58.33%** (+1.19%) |
| 平均利益 | 0.0621円 | **0.1245円** (+100%) |
| 取引数 | 14回 | 12回 (-14%) |
| 最大利益 | 0.1455円 | **0.1950円** (+34%) |

**結論**: トレーリングストップを0.5%に最適化することで、総損益が2倍以上に向上し、勝率も改善されました。

---

## 🛠️ よく使うコマンド

### トレーディング操作

```bash
# 手動実行（テスト用）
php artisan trading:execute

# バックテスト実行
php artisan trading:backtest 0.5

# バックテスト（異なる閾値で）
php artisan trading:backtest 0.2 --trailing-offset=0.4
```

### モニタリング

```bash
# 現在のポジション確認
./show_current_positions.sh

# 取引ログ確認
./show_trading_logs.sh

# 価格履歴エクスポート
./export_price_history.sh > price_history.csv
```

### データベース操作

```bash
# マイグレーション実行
php artisan migrate

# データベースリセット
php artisan migrate:fresh

# キャッシュクリア
php artisan cache:clear
php artisan config:clear
```

---

## 📊 戦略パラメータ

| パラメータ | 設定値 | 説明 |
|----------|--------|------|
| **lookback_period** | 20本 | 高値安値の判定期間 |
| **breakout_threshold** | 0.5% | ブレイクアウト判定の閾値 |
| **max_spread_percentage** | 0.1% | エントリー時の最大許容スプレッド |
| **stop_loss_percent** | 1.0% | 固定損切り幅 |
| **trailing_stop_offset** | **0.5%** ⭐ | トレーリングストップのオフセット（最適化済み） |
| **max_positions** | 3 | 同一方向の最大ポジション数 |
| **trade_size** | 0.01 XRP | 1ポジションあたりの取引量 |

パラメータは `config/trading.php` または `.env` で変更可能。

---

## 🔐 セキュリティ

### APIキー管理

- ❌ **絶対に** `.env` ファイルをコミットしない
- ✅ `.env.example` にはダミー値のみ記載
- ✅ 本番環境では環境変数を使用
- ✅ APIキーは読み取り専用権限を推奨

### ライブトレードの前に

1. ✅ ペーパートレードで**十分にテスト**
2. ✅ 少額から開始（最小取引量で）
3. ✅ ストップロス・利益確定の設定を確認
4. ✅ `trading_logs` テーブルで全取引履歴を追跡
5. ✅ エラー発生時は即座に停止

---

## 📂 プロジェクト構造

```
systrading/
├── app/
│   ├── Console/Commands/
│   │   ├── TradingExecute.php          # メイン実行コマンド
│   │   ├── RecordPriceHistory.php      # 価格履歴記録
│   │   └── BacktestStrategy.php        # バックテスト
│   ├── Models/
│   │   ├── Position.php                # ポジションモデル
│   │   ├── TradingLog.php              # 取引ログ
│   │   └── PriceHistory.php            # 価格履歴
│   └── Trading/
│       ├── Strategy/
│       │   ├── TradingStrategy.php     # 戦略基底クラス
│       │   └── HighLowBreakoutStrategy.php  # 実装戦略
│       ├── Exchange/
│       │   ├── ExchangeClient.php      # インターフェース
│       │   ├── PaperTradingClient.php  # ペーパートレード
│       │   ├── GMOCoinClient.php       # GMOコイン
│       │   └── LiveTradingClient.php   # Binance
│       └── Executor/
│           └── OrderExecutor.php       # 注文実行・リスク管理
├── config/
│   └── trading.php                     # トレーディング設定
├── database/
│   ├── migrations/                     # マイグレーション
│   └── database.sqlite                 # SQLiteデータベース
├── TRADING_STRATEGY.md                 # 戦略ドキュメント
├── BACKTEST_COMPARISON.md              # バックテスト比較
└── show_current_positions.sh           # ポジション確認スクリプト
```

---

## 📝 ドキュメント

詳細なドキュメントはこちら：

- [CLAUDE.md](CLAUDE.md) - プロジェクト概要と開発ガイドライン
- [TRADING_STRATEGY.md](TRADING_STRATEGY.md) - トレーディング戦略の詳細
- [BACKTEST_COMPARISON.md](BACKTEST_COMPARISON.md) - バックテスト結果の比較

---

## 🤝 対応取引所

現在サポートされている取引所：

### GMOコイン (`EXCHANGE_NAME=gmo`)
- 日本の仮想通貨取引所
- APIドキュメント: https://api.coin.z.com/docs/
- 対応通貨ペア: BTC/JPY, ETH/JPY, XRP/JPY, LTC/JPY, BCH/JPY等

### Binance (`EXCHANGE_NAME=binance`)
- 世界最大の仮想通貨取引所
- APIドキュメント: https://binance-docs.github.io/apidocs/
- 対応通貨ペア: BTC/USDT, ETH/USDT等

---

## 🧪 テスト

```bash
# 手動テスト実行
php artisan trading:execute

# バックテスト（様々なパラメータで）
php artisan trading:backtest 0.5
php artisan trading:backtest 0.2 --trailing-offset=0.4
php artisan trading:backtest 0.5 --stop-loss=2.0
```

---

## 🚨 トラブルシューティング

### システムが動作しない

```bash
# プロセス確認
ps aux | grep "schedule:work"

# ログ確認
tail -f storage/logs/laravel.log

# データベース確認
php artisan tinker
>>> Position::count();
>>> TradingLog::latest()->first();
```

### エラーが発生する

```bash
# キャッシュクリア
php artisan cache:clear
php artisan config:clear

# データベース再構築
php artisan migrate:fresh

# 権限確認
chmod -R 775 storage bootstrap/cache
```

---

## 📄 ライセンス

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## ⚠️ 免責事項

このシステムは教育・研究目的で作成されています。

- 実取引での使用は自己責任で行ってください
- 仮想通貨取引には高いリスクが伴います
- 損失が発生する可能性があることを理解した上で使用してください
- 開発者は一切の責任を負いません

**推奨**: まずはペーパートレードで十分にテストし、戦略の挙動を理解してから実取引に移行してください。

---

## 📮 お問い合わせ

質問や不具合報告は Issue を作成してください。

---

**Happy Trading! 📈**
