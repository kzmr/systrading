<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 価格履歴の記録を1分ごとに実行
// 戦略の稼働状況に依存せず、検証用データを継続して収集する
Schedule::command('price:record')->everyMinute()->withoutOverlapping();

// 板情報の記録を1分ごとに実行
// 板は過去に遡って取得できないため、検証したくなる前から蓄積しておく
Schedule::command('orderbook:record')->everyMinute()->withoutOverlapping();

// 市場横断スナップショット(日本プレミアム・取引所間価格差)を1分ごとに記録
// 為替の時間足履歴は60日分しか遡れないため、今から蓄積する必要がある
Schedule::command('market:record')->everyMinute()->withoutOverlapping();

// S&P500のセッション情報を5分ごとに記録(SpxReversalStrategy のシグナル源)
// 米国市場は 13:30〜21:00 UTC。前後に余裕を持たせた時間帯のみ実行する
Schedule::command('spx:record')
    ->everyFiveMinutes()
    ->between('13:00', '23:00')   // UTC(サーバのタイムゾーン設定に依存)
    ->withoutOverlapping();

// トレーディングコマンドを1分ごとに実行
Schedule::command('trading:execute')->everyMinute();

// 撤退基準の監視。条件を満たした戦略を自動停止する
// 手動監視だと判断が先延ばしになるため機械的に執行する
Schedule::command('strategy:guard')->everyFifteenMinutes()->withoutOverlapping();
