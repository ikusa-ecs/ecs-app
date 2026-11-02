<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 常勤のスタッフの印（2026-11-02 小沼さん）。
 * それまでスタッフ名簿の「常勤」チェックは can_view_sizes（サイズ一覧を見られる）そのものだった。
 * →「サイズ一覧はずっと見るわけじゃないので、つど見せる」に変更：
 *   ・is_fulltime＝常勤（スタッフ名簿でチェック）。
 *   ・can_view_sizes＝いまサイズ一覧を見せている（サイズ一覧の画面で社員が付け外し）。見られるのは常勤の人だけ。
 * これまでチェックされていた人は、常勤にもそのまま移す（見せている状態も残す）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('people', 'is_fulltime')) {
            Schema::table('people', function (Blueprint $table) {
                $table->boolean('is_fulltime')->default(false);
            });
            if (Schema::hasColumn('people', 'can_view_sizes')) {
                DB::table('people')->where('can_view_sizes', true)->update(['is_fulltime' => true]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('people', 'is_fulltime')) {
            Schema::table('people', function (Blueprint $table) {
                $table->dropColumn('is_fulltime');
            });
        }
    }
};
