# GMOコイン APIセットアップガイド

このガイドでは、GMOコインのAPIキーを取得して、自動トレーディングシステムに接続する方法を説明します。

## 前提条件

- GMOコインのアカウントを持っていること
- 本人確認が完了していること
- 二段階認証が設定されていること

## ステップ1: APIキーの取得

1. [GMOコイン](https://coin.z.com/jp/)にログイン
2. 会員ページの「API」メニューに移動
3. 「新規作成」をクリック
4. API名を設定（例: "自動トレーディング"）
5. 必要な権限を選択：
   - ✅ 注文情報取得
   - ✅ 取引余力取得
   - ✅ 資産残高取得
   - ✅ 注文
   - ✅ 注文変更
   - ✅ 注文キャンセル
6. IPアドレス制限を設定（セキュリティ強化のため推奨）
7. 「作成」をクリック

⚠️ **重要**: APIキーとシークレットは一度しか表示されません。必ず安全な場所に保存してください。

## ステップ2: 環境変数の設定

プロジェクトの `.env` ファイルを編集：

```bash
# Trading Configuration
TRADING_MODE=live
EXCHANGE_NAME=gmo
EXCHANGE_BASE_URL=https://api.coin.z.com
EXCHANGE_API_KEY=your_api_key_here
EXCHANGE_API_SECRET=your_api_secret_here
```

## ステップ3: トレーディング設定の登録

データベースにGMOコイン用の戦略を登録：

```bash
php artisan tinker
```

```php
use App\Models\TradingSettings;

// BTC取引設定
TradingSettings::create([
    'name' => 'BTC移動平均戦略',
    'symbol' => 'BTC/JPY',
    'strategy' => 'App\\Trading\\Strategy\\SimpleMovingAverageStrategy',
    'parameters' => [
        'short_period' => 5,
        'long_period' => 20,
        'trade_size' => 0.01, // 0.01 BTC
    ],
    'is_active' => true,
]);
```

## ステップ4: 動作確認

### ペーパートレードでテスト（推奨）

まずはペーパートレードモードでテスト：

```bash
# .envを変更
TRADING_MODE=paper

# 実行
php artisan trading:execute
```

### ライブトレードに移行

十分にテストした後、ライブトレードに切り替え：

```bash
# .envを変更
TRADING_MODE=live

# 実行
php artisan trading:execute
```

## GMOコイン対応通貨ペア

システムでサポートされているGMOコインの通貨ペア：

- `BTC/JPY` - ビットコイン
- `ETH/JPY` - イーサリアム
- `XRP/JPY` - リップル
- `LTC/JPY` - ライトコイン
- `BCH/JPY` - ビットコインキャッシュ
- `XLM/JPY` - ステラルーメン
- `BAT/JPY` - ベーシックアテンショントークン
- `XTZ/JPY` - テゾス
- `QTUM/JPY` - クアンタム
- `ENJ/JPY` - エンジンコイン
- `DOT/JPY` - ポルカドット
- `ATOM/JPY` - コスモス
- `XYM/JPY` - シンボル
- `MONA/JPY` - モナコイン
- `ADA/JPY` - カルダノ
- `MKR/JPY` - メイカー
- `DAI/JPY` - ダイ
- `LINK/JPY` - チェインリンク

## トラブルシューティング

### エラー: "API-KEY is invalid"

- APIキーとシークレットが正しいか確認
- APIキーの権限を確認
- IPアドレス制限を確認

### エラー: "Insufficient balance"

- 取引余力が不足しています
- 入金するか、`trade_size`を小さくしてください

### エラー: "Order size is too small"

- GMOコインには最小注文数量があります
- 各通貨の最小注文数量を確認してください

## セキュリティのベストプラクティス

1. **APIキーの管理**
   - APIキーは絶対に公開しない
   - `.env`ファイルをgitにコミットしない
   - 定期的にAPIキーをローテーションする

2. **権限の最小化**
   - 出金権限は付与しない
   - 必要最小限の権限のみを付与

3. **IPアドレス制限**
   - 可能な限りIPアドレス制限を設定
   - VPNを使用する場合は注意

4. **監視とアラート**
   - ログを定期的に確認
   - 異常な取引がないかモニタリング
   - アラート設定を活用

## 参考リンク

- [GMOコイン公式サイト](https://coin.z.com/jp/)
- [GMOコイン API ドキュメント](https://api.coin.z.com/docs/)
- [GMOコイン サポート](https://support.coin.z.com/hc/ja)
