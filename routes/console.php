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

// トレーディングコマンドを1分ごとに実行
Schedule::command('trading:execute')->everyMinute();
