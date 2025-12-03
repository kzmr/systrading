# メール通知設定ガイド

エントリー・エグジット時にメール通知を受け取る設定方法を説明します。

## 📧 機能概要

トレーディングシステムで以下のアクションが発生した際にメール通知が送信されます：

- **エントリー通知**: ロング/ショートポジションの新規エントリー時
- **エグジット通知**: ポジションのクローズ時（損益情報含む）

## ⚙️ 設定手順

### 1. .envファイルを編集

`.env`ファイルに以下の設定を追加します：

```bash
# メール通知の有効化
TRADING_NOTIFICATION_ENABLED=true
TRADING_NOTIFICATION_EMAIL=your_email@example.com

# メールドライバー設定（Gmail例）
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_gmail@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your_gmail@gmail.com
MAIL_FROM_NAME="XRP Trading Bot"
```

### 2. Gmailの場合の追加設定

Gmail を使用する場合は**アプリパスワード**の設定が必要です：

1. Googleアカウントにログイン
2. [セキュリティ] → [2段階認証プロセス] を有効化
3. [アプリパスワード] を生成
4. 生成されたパスワードを `MAIL_PASSWORD` に設定

### 3. その他のメールサービス

#### Mailtrap（開発・テスト用）

```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
```

#### SendGrid

```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your_sendgrid_api_key
MAIL_ENCRYPTION=tls
```

#### Amazon SES

```bash
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
MAIL_FROM_ADDRESS=verified_email@example.com
```

### 4. ログ出力モード（テスト用）

メールを実際に送信せず、ログに出力するだけの設定：

```bash
TRADING_NOTIFICATION_ENABLED=true
TRADING_NOTIFICATION_EMAIL=test@example.com
MAIL_MAILER=log
```

この設定では、メール内容が `storage/logs/laravel.log` に出力されます。

## 📬 通知メールの内容

### エントリー通知例

```
件名: 📈 XRP/JPY ロングエントリー通知

アクション: エントリー
通貨ペア: XRP/JPY
方向: ロング (買い)
価格: 340.50 円
数量: 0.01
```

### エグジット通知例

```
件名: 💰 XRP/JPY ロングエグジット通知

アクション: エグジット
通貨ペア: XRP/JPY
方向: ロング (買い)
価格: 345.20 円
数量: 0.01
損益: +0.047 円 (+1.38%)
理由: トレーリングストップ到達
```

## 🧪 メール送信テスト

以下のコマンドでメール送信をテストできます：

```bash
php artisan tinker
```

Tinkerで以下を実行：

```php
use App\Mail\TradingNotification;
use Illuminate\Support\Facades\Mail;

Mail::to(env('TRADING_NOTIFICATION_EMAIL'))
    ->send(new TradingNotification(
        action: 'entry',
        side: 'long',
        symbol: 'XRP/JPY',
        price: 340.50,
        quantity: 0.01
    ));
```

## 🔧 トラブルシューティング

### メールが届かない場合

1. **ログを確認**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **設定を確認**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. **接続テスト**
   - SMTPホスト・ポートが正しいか確認
   - ファイアウォールでポートがブロックされていないか確認
   - 認証情報（ユーザー名・パスワード）が正しいか確認

### Gmail特有の問題

- **「安全性の低いアプリのアクセス」エラー**
  → アプリパスワードを使用してください

- **2段階認証が有効でない**
  → 2段階認証を有効化してからアプリパスワードを生成

### 通知が有効にならない場合

`.env`ファイルを編集した後は必ずキャッシュをクリア：

```bash
php artisan config:clear
php artisan cache:clear
```

その後、システムを再起動：

```bash
pkill -f schedule:work
php artisan schedule:work
```

## 📊 本番運用での推奨設定

- **専用のメールアドレスを使用**: トレーディング通知専用のメールアドレスを作成
- **フィルターを設定**: 重要な通知を見逃さないようGmail等でフィルタ・ラベルを設定
- **モバイル通知**: スマホアプリで通知を有効化し、リアルタイムで受け取る
- **信頼性の高いサービス**: SendGrid、Amazon SESなど本番向けサービスの使用を推奨

## ⚠️ 注意事項

- メール送信には外部サービスへの接続が必要です
- 大量の通知が発生する可能性があるため、メール送信制限に注意してください
- 機密情報（APIキー等）をメール本文に含めないよう注意してください
- ペーパートレードでもメール通知は送信されます

## 🔐 セキュリティ

- `.env`ファイルは絶対にGitにコミットしないでください
- メールパスワードは環境変数で管理してください
- 可能な限りアプリパスワードを使用してください
- 定期的にアプリパスワードをローテーションしてください

---

**最終更新**: 2025-12-04
**バージョン**: v1.0
