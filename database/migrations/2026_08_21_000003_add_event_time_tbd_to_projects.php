<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「イベント時間（入場・開始・終了）が未定」を持たせる（2026-08-21 baba要望）。
 *
 * これまでは空欄にするしかなく、「まだ決まっていない」のか「入れ忘れ」なのか区別できなかった。
 * チェックを入れると、案件一覧とスタッフ画面に「本番時間未定」と出す＝決まっていないことが伝わる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('event_time_tbd')->default(false)->after('event_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('event_time_tbd');
        });
    }
};
