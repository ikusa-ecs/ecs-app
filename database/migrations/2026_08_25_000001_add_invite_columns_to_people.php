<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ログイン案内（招待）メールのための2列を足す（2026-08-25 baba要望）。
 *
 * ① invited_at        … ログイン案内メールを最後に送った日時。
 *    なぜ要るか＝「この人にはもう送ったか？」が名簿で分かるようにするため。
 *    スタッフからメールアドレスをもらった順に送っていく運用なので、送り漏れ・二重送信を防ぐ。
 *
 * ② password_set_at   … 本人が自分でパスワードを決めた日時。
 *    なぜ要るか＝招待リンクからパスワードを決めた人に、初回設定の画面で
 *    もう一度パスワードを聞かないため（二度手間になる）。
 *    あわせて名簿で「まだ設定していない人」も分かる。
 *    ⚠ 管理者が発行した仮パスワードでは立てない（本人が決めたときだけ）。
 *
 * どちらも空でよい＝既存データの移行は不要。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('must_onboard');
            $table->timestamp('password_set_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'password_set_at']);
        });
    }
};
