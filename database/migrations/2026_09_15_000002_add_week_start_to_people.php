<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * カレンダーの「週のはじまり」を人ごとに覚える列（2026-09-15 baba要望）。
 *
 * 画面の色（people.theme）と同じ考え方＝端末ではなくアカウントに覚えさせる
 * （会社のPCでもスマホでも同じ並びになる）。
 *
 * ⚠ 空（null）＝既定。既定を変えたいときは列ではなく App\Support\WeekStart::DEFAULT を直す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('week_start', 10)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('week_start');
        });
    }
};
