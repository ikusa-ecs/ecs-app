<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スタッフ画面の「追加」と、案件の区分（追加案件）を分ける（2026-10-07 baba要望）。
 *
 * 【これまで】公開ボードの「追加」ボタンが、案件の区分（category）そのものを書き換えていた。
 *   スタッフ画面で上に出したくない追加案件の「追加」を外すと、区分が通常案件に変わり、
 *   **集計で数えている「追加で何件増えたか」から消えていた**。
 * 【これから】staff_extra＝スタッフ画面で「追加」として上に出すか。区分は公開ボードから触らない。
 *   ・⚠ null＝まだ公開ボードで押していない＝**区分に合わせる**（今の見え方を変えない）。
 *   正本＝App\Support\StaffExtra。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('staff_extra')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('staff_extra');
        });
    }
};
