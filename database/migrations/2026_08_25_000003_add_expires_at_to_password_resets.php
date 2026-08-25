<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 再設定リンクに「いつまで有効か」を持たせる（2026-08-25 不具合の修正）。
 *
 * 【なぜ必要か】
 * ログイン案内メール（初回のパスワード設定）と「パスワードをお忘れの方」は、
 * 同じ password_resets を共用している。これまでは期限を created_at から一律60分で
 * 計算していたため、案内メールに「7日間有効」と書いてあるのに実際は1時間で切れ、
 * 「この再設定リンクは無効か、期限が切れています」と出ていた。
 * リンクごとに期限が違うので、発行するときに期限そのものを書いておく。
 *
 * ⚠ 既に発行済みの行は expires_at が空になる。空のときは今までどおり
 *   「created_at＋60分」で判定する（＝切り替えの瞬間に有効なリンクが変にならない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_resets', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('password_resets', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
