<?php

namespace App\Providers;

use App\Trading\Exchange\ExchangeClient;
use App\Trading\Exchange\ExchangeClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 設定に応じた取引所クライアント（テストではモックに差し替え可能）
        $this->app->bind(ExchangeClient::class, fn() => ExchangeClientFactory::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
