<?php

namespace App\Providers;

use App\Auth\PersonUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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

        // ⚠ @json を差し替える（2026-08-31・本番でスタッフ画面が丸ごと止まった対策）。
        //   もとの @json は、文字コードが壊れたデータが1件でもあると**何も出さない**ので、
        //        window.ECS_RECRUIT_JOBS = ;
        //   という文法エラーの行になり、その画面のJavaScriptが丸ごと読み込めなくなる
        //   （案件一覧が空・タブもボタンも押せない・でも画面は普通に出るので気づけない）。
        //   差し替え後は壊れた文字を「�」に置き換えて、**必ず正しいJSONを出す**。
        //   正本＝App\Support\SafeJson。画面側は今までどおり @json と書けばよい。
        //   ⚠ この差し替えを消すと、また同じ止まり方が起きる。
        Blade::directive('json', function ($expression) {
            return "<?php echo \App\Support\SafeJson::forScript({$expression}); ?>";
        });
    }
}
