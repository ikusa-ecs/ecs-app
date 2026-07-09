<?php

namespace App\Providers;

use App\Auth\PersonUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // people 名簿用のユーザープロバイダを登録（config/auth.php で driver='person' を指定）。
        // テスト用アカウントのときだけ DB を見ずにログインできる（App\Support\TestAccounts）。
        Auth::provider('person', function ($app, array $config) {
            return new PersonUserProvider($app['hash'], $config['model']);
        });
    }
}
