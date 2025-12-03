<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 0 0 10px 10px;
        }
        .info-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
        }
        .info-value {
            font-weight: 700;
            text-align: right;
        }
        .profit {
            color: #28a745;
        }
        .loss {
            color: #dc3545;
        }
        .entry {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
        }
        .exit {
            background: #fff4e5;
            border-left: 4px solid #ff9800;
        }
        .long-side {
            color: #28a745;
            font-weight: bold;
        }
        .short-side {
            color: #dc3545;
            font-weight: bold;
        }
        .reason-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            @if($action === 'entry')
                📈 エントリー通知
            @else
                💰 エグジット通知
            @endif
        </h1>
    </div>

    <div class="content">
        <div class="info-box {{ $action === 'entry' ? 'entry' : 'exit' }}">
            <div class="info-row">
                <span class="info-label">アクション</span>
                <span class="info-value">
                    @if($action === 'entry')
                        <strong>エントリー</strong>
                    @else
                        <strong>エグジット</strong>
                    @endif
                </span>
            </div>

            <div class="info-row">
                <span class="info-label">通貨ペア</span>
                <span class="info-value"><strong>{{ $symbol }}</strong></span>
            </div>

            <div class="info-row">
                <span class="info-label">方向</span>
                <span class="info-value">
                    @if($side === 'long')
                        <span class="long-side">ロング (買い)</span>
                    @else
                        <span class="short-side">ショート (売り)</span>
                    @endif
                </span>
            </div>

            <div class="info-row">
                <span class="info-label">価格</span>
                <span class="info-value"><strong>{{ number_format($price, 2) }} 円</strong></span>
            </div>

            <div class="info-row">
                <span class="info-label">数量</span>
                <span class="info-value">{{ $quantity }}</span>
            </div>

            @if($action === 'exit' && $profitLoss !== null)
                <div class="info-row">
                    <span class="info-label">損益</span>
                    <span class="info-value {{ $profitLoss >= 0 ? 'profit' : 'loss' }}">
                        <strong>{{ $profitLoss >= 0 ? '+' : '' }}{{ number_format($profitLoss, 4) }} 円</strong>
                        @if($profitLossPercent !== null)
                            ({{ $profitLoss >= 0 ? '+' : '' }}{{ number_format($profitLossPercent, 2) }}%)
                        @endif
                    </span>
                </div>
            @endif
        </div>

        @if($reason)
            <div class="reason-box">
                <strong>理由:</strong> {{ $reason }}
            </div>
        @endif

        @if(!empty($additionalData))
            <div class="info-box">
                <h3 style="margin-top: 0;">追加情報</h3>
                @foreach($additionalData as $key => $value)
                    <div class="info-row">
                        <span class="info-label">{{ $key }}</span>
                        <span class="info-value">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="footer">
            <p>このメールは自動トレーディングシステムから送信されました</p>
            <p>{{ now()->format('Y-m-d H:i:s') }}</p>
        </div>
    </div>
</body>
</html>
