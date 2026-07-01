<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects に「スタッフ向けの集合・解散時間」を追加。
 * 社員の集合・解散（start_time/end_time）とは別に、スタッフに見せる時間を持てるようにする。
 * 空（null）のときは社員の時間をそのまま使う。公開ボードで担当が直すと、ここに保存される。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('staff_meet_time')->nullable()->after('end_time');
            $table->string('staff_leave_time')->nullable()->after('staff_meet_time');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['staff_meet_time', 'staff_leave_time']);
        });
    }
};
