<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * people 名簿に「ログイン」の土台を追加する。
 * ・password        … ログイン用パスワード（暗号化して保存。空＝まだ発行していない人）
 * ・permission      … 操作権限の4段階（admin=Administrator / manager=管理者 / employee=社員 / staff=スタッフ）
 * ・remember_token  … 「ログイン状態を保持」に使う標準の列
 *
 * 設計：ログインアカウントは users 表ではなく people 名簿に持たせる（名簿が1つ＝正が1つ）。
 * 権限4段階は 2026-07-02 baba 確定（削除・権限付与・システム変更＝Administratorのみ／作成＝管理者以上）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');   // 暗号化済みパスワード
            $table->string('permission')->nullable()->after('is_admin'); // admin/manager/employee/staff
            $table->rememberToken();                                   // remember_token（ログイン保持）
        });

        // ── 既存データの権限を振り分け（新規に作り直さず、今ある行を埋める）──
        //   ① 全権管理者フラグが立っている人 → admin（例：baba E-007）
        DB::table('people')->where('is_admin', true)->update(['permission' => 'admin']);
        //   ② スタッフ → staff
        DB::table('people')->whereNull('permission')->where('role', 'staff')->update(['permission' => 'staff']);
        //   ③ 残り（社員）→ employee
        DB::table('people')->whereNull('permission')->update(['permission' => 'employee']);

        // ── 開発・動作確認用の仮パスワードを全員に付与（本番前に必ず入れ替える）──
        //   ダミーの見本アカウント（@example.com）だけの想定。実データ投入時は各自が設定する。
        DB::table('people')->whereNull('password')->update(['password' => Hash::make('password')]);
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['password', 'permission', 'remember_token']);
        });
    }
};
