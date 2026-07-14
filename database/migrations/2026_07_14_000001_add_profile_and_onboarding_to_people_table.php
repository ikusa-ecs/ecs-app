<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * people 名簿に「初回ログインで本人が入れる項目」と「初回設定フラグ」を追加する。
 *
 * ・height          … 身長（cm・当日の衣装/ユニフォーム準備の参考）
 * ・prefecture      … 都道府県（住まいの地域）
 * ・nearest_station … 最寄り駅
 * ・must_onboard    … 初回ログイン時に「パスワード変更＋プロフィール入力」を求めるか
 *                     （管理者が発行したての人＝true。本人が初期設定を終えると false にする）
 *
 * ※ 靴のサイズ(shoe_size)・服のサイズ(shirt_size)は既に people にある列を使う。
 * ※ 追記のみ＝既存データは消えない。反映は「php artisan migrate」（migrate:fresh は使わない）。
 * ※ もともと自己登録(/register)で聞いていた「基本情報」を、本人が初回ログインで入れる運用に置き換えるためのもの。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('height')->nullable()->after('shoe_size');           // 身長（cm）
            $table->string('prefecture')->nullable()->after('height');          // 都道府県
            $table->string('nearest_station')->nullable()->after('prefecture'); // 最寄り駅
            $table->boolean('must_onboard')->default(false)->after('permission'); // 初回設定が必要か
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['height', 'prefecture', 'nearest_station', 'must_onboard']);
        });
    }
};
