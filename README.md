# BTC/JPY Automated Trading System

<p align="center">
  <img src="Gemini_Generated_Image_euy57heuy57heuy5.png" alt="Trading System Overview" width="800">
</p>

<p align="center">
  <strong>Laravel 12 + PHP 8.3 ベースの仮想通貨自動トレーディングシステム</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-red" alt="Laravel 12">
  <img src="https://img.shields.io/badge/PHP-8.3-blue" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/Exchange-GMO_Coin-orange" alt="Exchange">
  <img src="https://img.shields.io/badge/Tests-125_passing-brightgreen" alt="Tests">
</p>

---

## 概要

GMOコインでの BTC/JPY 自動売買システム。戦略の実行だけでなく、**戦略が有効かどうかを検証するための基盤**を重視した構成になっています。

過去に複数の戦略を運用しましたが、検証の結果いずれも手数料を超える優位性がないと判明し、停止しました。現在は検証を通過した1つの戦略を少額で実運用しつつ、次の候補を探すためのデータを収集しています。

検証の経緯と結果は **[Issue #27 作業ログ](../../issues/27)** に記録しています。

### 現在の稼働状況

| 項目 | 状態 |
|---|---|
| 稼働中の戦略 | 1件（米国株急落後のBTC反発戦略・0.001 BTC） |
| 停止中の戦略 | 6件（優位性が確認できず停止） |
| データ収集 | 価格・板情報・市場横断・S&P500セッション |
| 撤退基準 | 自動執行（累計-3,000円 または 20取引で期待値マイナス） |

---

## 稼働中の戦略

### SpxReversalStrategy（米国株急落後のBTC反発）

米国市場でS&P500が大きく下げた日、引け後にBTCが反発する傾向を捉えます。

**「価格から将来の価格を当てる」のではなく、別市場の情報を使う**点が、過去に停止した戦略との違いです。

| 項目 | 内容 |
|---|---|
| エントリー | S&P500のセッション変動が -0.40% 以下で引けた後、60分以内 |
| 決済 | 4時間経過（時間のみ。価格ベースの決済なし） |
| サイズ | 0.001 BTC（約10,000円） |
| 頻度 | 月3〜4回 |

**検証結果**（事前に基準を宣言したうえでの検証）

| 期間 | 該当 | 期待値 | t値 | 勝率 |
|---|---|---|---|---|
| 学習 2024-05〜2025-08 | 77件 | +0.2279% | 2.26 | 62.3% |
| 検証 2025-09〜2026-08 | 34件 | +0.2926% | 2.13 | 64.7% |

保有時間は2〜8時間、閾値は下位10〜25%の範囲で安定しており、単一の最適値に依存していません。

**既知の弱点**: 年別では利益が2024年に偏っています（2024年 +0.366% に対し2025年 +0.055%、2026年 +0.037%）。2024年を除くとほぼ損益トントンです。

**この運用の位置づけ**: 年間の想定利益は約650円で、収益目的ではありません。バックテストでは見えない要素（実際の約定価格、スリッページ、シグナルのタイミングのずれ）を、限定的なリスクで実測することが目的です。

---

## 停止中の戦略と、その理由

過去に運用していた戦略は、検証の結果すべて停止しました。**同じ失敗を繰り返さないために記録しています。**

| 戦略 | 実運用の結果 | 停止理由 |
|---|---|---|
| HighLowBreakout | 155取引 / -16,263円 | 手数料前ですら赤字。優位性なし |
| RSIContrarian | 79取引 / +1,065円 | 全期間で PF 0.4前後。黒字は2026年1月に集中 |

### 検証して否定された仮説

```
✗ ブレイクアウト戦略        ✗ 板情報の偏り（静的）
✗ RSI逆張り戦略             ✗ 時間帯・曜日効果
✗ 短期の方向予測（5〜15分）  ✗ ボラティリティ・レジーム
✗ 4時間モメンタム           ✗ Fear & Greed 指数
✗ トレンドフォロー（長期）   ✗ 金との相関（デジタルゴールド仮説）
✗ トレンドフィルター        ✗ 出口・資金管理での期待値改善
```

主な発見:

- **5分保有では方向を100%当てても平均的に負ける**（平均変動0.076% < 往復手数料0.106%）
- **勝率と期待値は完全にトレードオフ**。損切りを広げれば勝率は上がるが期待値は同じだけ下がる
- **BTCは金（相関+0.109）より S&P500（+0.424）と4倍強く連動**する。「デジタルゴールド」は値動きの実態ではない
- 2025年に金が +62.7% 上昇した年、BTCは -9.6% だった

---

## 検証の方法論

このシステムで最も重要な資産は、**戦略を正しく評価する手続き**です。過去に「PF 1.76」という優秀な数字を根拠に投入した戦略が、実際には赤字だった経験から確立しました。

### 検証ルール

**1. 期間を分割し、検証期間は一度だけ使う**

```
学習期間: パラメータ調整はここだけで行う
検証期間: 調整完了後に一度だけ測る
最終確認: 学習・検証の後に確認
```

**2. 合格基準を結果を見る前に決める**

| 指標 | 基準 | 理由 |
|---|---|---|
| プロフィットファクター | 1.3以上 | 1.1〜1.2では手数料・スリッページで消える |
| 取引数 | 100件以上 | 少ないと偶然と区別できない |
| 学習→検証の劣化 | 30%以内 | 過剰最適化の検出 |
| 調整パラメータ数 | 3個以内 | 少ないほど壊れにくい |

**3. ベンチマークと比較する**

「シグナルAで買うと +11.2%」だけでは不十分です。「常に買った場合 +3.7%」と比較して初めて優位性が測れます。上昇相場のバイアスを優位性と誤認しないために必須です。

**4. 条件の出現頻度も検証する**

効果の大きさだけでなく、**各期間で十分な回数成立するか**を先に確認します。学習期間で11.2%の頻度があった条件が、検証期間では0%だった事例があります。成立しなければ検証すらできません。

**5. 検出力を確認する**

4時間足の1本あたり標準偏差は約1.0%、探している優位性は0.1〜0.2%です。ノイズが10倍大きいため、サンプル数が不足すると「マイナス」と「判別不能」を取り違えます。

**6. 多重比較を数える**

82通りの条件を検定すれば、偶然 t>2 になるものが4件程度は必ず出ます。検定数と期待される偽陽性数を常に併記します。

### 陥りやすい落とし穴

**時刻の同時性**

複数のデータ源を組み合わせる際は、まず時刻規約を確認してください。日足の区切りが GMO 21:00 UTC / Binance 00:00 UTC / Yahoo 23:00 UTC とバラバラだったため、標準偏差2.3%・最大±12%という**完全に偽の指標**が算出された事例があります。

**データの重複**

価格記録が `OrderExecutor::execute()` の副作用だった時期、稼働中の戦略の数だけ同じ価格が重複記録されていました。RSI(80) が実質40分ぶんの値動きしか見ておらず、本番ログとの比較で**平均13.6ポイント、最大67.2ポイント**の乖離が生じていました。現在は `price:record` に分離し、DBのユニーク制約で再発を防いでいます。

**バックテストと本番の乖離**

バックテストが本番の決済ロジックを再現していない状態が長く続きました。トレーリング決済・トレンドフィルター・クールダウンの3つが未実装で、バックテストが実運用の8倍取引していました。現在はすべて実装済みです。

---

## アーキテクチャ

### 3層設計

```
Strategy Layer   （app/Trading/Strategy/）
  戦略ロジック。analyze() でシグナルを返す
  shouldClosePosition() で戦略固有の決済条件を定義（任意）
        ↓
Executor Layer   （app/Trading/Executor/）
  注文実行・ポジション管理・リスク管理
        ↓
Exchange Layer   （app/Trading/Exchange/）
  取引所API。GMOコイン / Binance / ペーパートレード
```

**戦略はExecutorから完全に独立**しています。戦略はシグナルを返すだけで、注文の出し方やポジション管理を知りません。

### マルチ戦略運用時の分離

同一通貨ペアで複数戦略を運用する場合:

| 対象 | 分離 |
|---|---|
| エントリー・ポジション数上限 | `trading_settings_id` で自戦略のみ |
| 逆方向決済 | 同上 |
| 損切り・トレーリング | 全ポジションに適用。ただし**適用する%は各ポジション所有戦略の設定** |

過去に、実行中の戦略のパラメータを全戦略のポジションに適用してしまう不具合がありました。決済指値が本来9,800であるべきところ9,950で発注されていた事例です。

### データ収集

売買の稼働状況に**依存せず**動きます。停止中も検証用データが貯まり続けます。

| コマンド | 頻度 | 内容 |
|---|---|---|
| `price:record` | 毎分 | 価格履歴（3銘柄） |
| `orderbook:record` | 毎分 | 板情報の集計値（3銘柄） |
| `market:record` | 毎分 | 市場横断（日本プレミアム・取引所間価格差） |
| `spx:record` | 5分 | S&P500セッション（13:00-23:00 UTC） |

### 安全装置

| コマンド | 頻度 | 内容 |
|---|---|---|
| `strategy:guard` | 15分 | 撤退基準に抵触した戦略を自動停止 |

撤退基準は `trading_settings.parameters` に設定します。

```json
{
  "max_cumulative_loss": 3000,
  "evaluation_trade_count": 20
}
```

判定はすべて手数料控除後の純損益で行います。手動監視だと判断が先延ばしになるため、機械的に執行します。

---

## データベース

| テーブル | 内容 |
|---|---|
| `trading_settings` | 戦略の設定（全パラメータをJSONで管理） |
| `positions` | ポジション。手数料も記録し純損益を算出 |
| `trading_logs` | 全取引の実行ログ |
| `price_history` | 価格履歴。`(symbol, recorded_at)` にユニーク制約 |
| `order_book_snapshots` | 板情報の集計値 |
| `cross_market_snapshots` | 市場横断（日本プレミアム等） |
| `spx_sessions` | S&P500のセッション情報 |

**全トレーディングパラメータはDBで一元管理**します。`.env` には環境依存の設定（API認証情報等）のみを置きます。

---

## クイックスタート

### 1. 環境構築

```bash
git clone <repository-url>
cd systrading

composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### 2. 設定

`.env` には環境依存の設定のみを記載します。

```bash
TRADING_MODE=paper          # paper: ペーパートレード / live: 実取引
EXCHANGE_NAME=gmo
EXCHANGE_BASE_URL=https://api.coin.z.com
EXCHANGE_API_KEY=your_api_key_here
EXCHANGE_API_SECRET=your_api_secret_here
```

トレーディングパラメータは `trading_settings.parameters` で管理します。

### 3. 起動

```bash
# スケジューラ（データ収集・売買・撤退監視をすべて実行）
php artisan schedule:work

# ポジション高頻度監視（15秒ごと・任意）
nohup php artisan trading:monitor --interval=15 >> storage/logs/monitor.log 2>&1 &
```

### 4. 停止

```bash
pkill -f schedule:work
kill $(pgrep -f "trading:monitor")
```

戦略単位で止める場合は `is_active` を変更します。

```bash
sqlite3 database/database.sqlite "UPDATE trading_settings SET is_active=0 WHERE id=7;"
```

---

## よく使うコマンド

### モニタリング

```bash
# 現在のポジションと損益
./show_current_positions.sh

# 撤退基準の現在値（停止せず確認）
php artisan strategy:guard --dry-run

# 取引ログ
./show_trading_logs.sh BTC/JPY 100

# リアルタイム監視
./tail_trading_logs.sh BTC/JPY
```

### バックテスト

本番の決済ロジックを再現するため、トレーリング・トレンドフィルター・クールダウンをすべて指定できます。

```bash
# RSI戦略（本番と同一条件）
php artisan trading:backtest-rsi --csv=storage/backtest/btc.csv --symbol=BTC/JPY \
  --rsi-period=80 --rsi-oversold=25 --rsi-overbought=75 \
  --rsi-exit-long=53 --rsi-exit-short=47 --max-hold=120 --stop-loss=2.0 \
  --initial-trailing=0.7 --trailing-offset=0.5 \
  --trend-ma-period=60 --trend-threshold=0.3 --cooldown=30

# HighLowBreakout
php artisan trading:backtest-breakout --csv=storage/backtest/btc.csv --symbol=BTC/JPY \
  --threshold=0.4 --lookback=30 --stop-loss=0.5 \
  --initial-trailing=0.7 --trailing-offset=1.0 \
  --vol-period=60 --vol-threshold=0.5

# パラメータ最適化
php artisan trading:backtest-breakout --optimize --csv=...
php artisan trading:backtest-breakout --optimize-vol --csv=...
```

**注意**: 最適化は学習期間のみで行い、検証期間は一度だけ使ってください。

### 検証用データのエクスポート

```bash
sqlite3 -header -csv database/database.sqlite \
  "SELECT id,symbol,price,recorded_at FROM price_history
   WHERE symbol='BTC/JPY' ORDER BY recorded_at;" > storage/backtest/btc.csv
```

### データベース操作

```bash
php artisan migrate
php artisan config:clear && php artisan cache:clear
```

**パラメータ更新は必ずヒアドキュメントで実行してください。**

```bash
sqlite3 database/database.sqlite <<'SQL'
UPDATE trading_settings SET parameters = '{"key":"value"}' WHERE id = 7;
SQL

# 確認（数値が返ればOK、NULLなら不正JSON）
sqlite3 database/database.sqlite \
  "SELECT json_extract(parameters,'\$.trade_size') FROM trading_settings WHERE id=7;"
```

シェルのダブルクォートで囲むとJSON内の `"` が剥がれ、不正なJSONが保存されます。過去に全パラメータがデフォルト値で動作し、1.0 BTC（約978万円）の誤発注が発生しかけた事例があります。

---

## 手数料

| 種別 | 料率 |
|---|---|
| Taker（成行注文・逆指値の執行） | 0.05% |
| Maker（指値注文） | -0.01%（リベート） |

**現在の実装はエントリー・決済とも Taker です。** 決済は逆指値（`stopSell` / `stopBuy`）で管理しており、逆指値はトリガー後に成行執行されるためリベートは発生しません。

実測値（BTC/JPY のクローズ済み175件）:

| | エントリー | 決済 | 往復 |
|---|---|---|---|
| HighLowBreakout | 0.0535% | 0.0529% | 約0.106% |
| RSIContrarian | 0.047% | 0.047% | 約0.094% |

決済を指値にすればリベートを得られますが、価格が飛んだ際に約定せず損失が拡大しえます。**逆指値は安全側の意図的な選択**であり、コストはその対価と考えています。

コスト削減の余地があるのはエントリー側（現在は成行）ですが、約定しない機会損失とのトレードオフがあり未検証です。

---

## GMO Coin MCP Server

Claude Code から GMOコインのデータへ直接アクセスするための MCP サーバー。

```bash
cd mcp-gmo-coin
npm install
npm run build
```

`.mcp.json` は設定済みです。提供ツール:

| ツール | 内容 |
|---|---|
| `get_ticker` | 現在価格・bid/ask・出来高 |
| `get_klines` | ローソク足（1min〜1month） |
| `get_orderbooks` | 板情報 |
| `get_trades` | 約定履歴 |
| `get_account_assets` | 残高（要API認証） |
| `get_active_orders` | 注文一覧（要API認証） |

---

## テスト

```bash
php artisan test

# 特定のテストのみ
php artisan test --filter=SpxReversalStrategyTest
```

125テスト（281アサーション）。特に以下を重点的に担保しています。

- 戦略間のポジション分離
- 撤退基準の自動執行（誤停止しないことを含む）
- バックテストが本番の決済ロジックを再現していること
- データ収集の冪等性

**本番サーバーでのテスト実行は避けてください。** `testing.` チャネルのログが本番ログに混入します。

---

## プロジェクト構造

```
app/
├── Console/Commands/
│   ├── TradingExecute.php          # 売買の実行（毎分）
│   ├── RecordPriceHistory.php      # 価格収集
│   ├── RecordOrderBook.php         # 板情報収集
│   ├── RecordCrossMarket.php       # 市場横断収集
│   ├── RecordSpxSession.php        # S&P500セッション収集
│   ├── GuardStrategyLimits.php     # 撤退基準の自動執行
│   ├── BacktestRSIStrategy.php     # RSI戦略のバックテスト
│   └── BacktestHighLowBreakout.php # ブレイクアウトのバックテスト
├── Trading/
│   ├── Strategy/                   # 戦略層
│   │   ├── TradingStrategy.php     # 基底クラス
│   │   ├── SpxReversalStrategy.php # 稼働中
│   │   ├── RSIContrarianStrategy.php
│   │   └── HighLowBreakoutStrategy.php
│   ├── Executor/
│   │   └── OrderExecutor.php       # 注文実行・リスク管理
│   ├── Exchange/                   # 取引所層
│   │   ├── ExchangeClient.php      # インターフェース
│   │   ├── GMOCoinClient.php
│   │   ├── ExchangeClientFactory.php
│   │   └── OrderBookFetcher.php
│   └── Market/                     # 外部市場データ
│       ├── CrossMarketFetcher.php
│       └── SpxSessionFetcher.php
└── Models/
```

---

## セキュリティ

- `.env` は絶対にコミットしない（`.env.example` にはダミー値のみ）
- 本番サーバーの接続情報をリポジトリに含めない（このリポジトリは public）
- ライブトレード前にペーパートレードで十分にテストする
- **撤退基準を稼働前に決め、自動執行させる**

---

## トラブルシューティング

### システムが動作しない

```bash
ps aux | grep schedule:work
php artisan schedule:list
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
```

ログが出力されない場合、稼働中の戦略が0件の可能性があります（正常）。

### 価格データが記録されない

`price:record` がスケジューラに登録されているか確認してください。過去に、価格記録が売買処理の副作用だったため**全戦略を停止すると価格収集も止まる**問題がありました。

### GMOコインAPIエラー（ERR-5201）

メンテナンス中のエラーです。定期メンテナンスは数時間続くことがあります。自動的にリトライされるため対応不要です。

---

## ライセンス

MIT License

---

## 免責事項

本システムは教育・研究目的で作成されています。実際の取引による損失について、作者は一切の責任を負いません。

**検証の結果、単純な価格ベースの戦略で手数料を超える優位性を見つけることは困難であると判明しています。** 現在稼働中の戦略も、年間の想定利益は約650円であり、収益を目的としたものではありません。

暗号資産取引にはリスクが伴います。必ず余裕資金の範囲で行ってください。
