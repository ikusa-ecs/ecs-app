<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件に「イベント数として数えるか」を持たせる（先人の要件定義 先-2）。
 *
 * これまで集計ダッシュボード（/stats）は開催日のある案件を全部数えていたため、
 * 社内で言う「イベント数」とズレていた（体験会・EXPO は数えない運用）。
 *
 * count_as_event の意味（3通り）：
 *   null  ＝ 自動（App\Support\EventCount のルールで決める。既定）
 *   true  ＝ 必ず数える（自動で外れる案件でも数えたいとき）
 *   false ＝ 必ず数えない
 *
 * 既定を null（自動）にしているので、既存の案件はそのまま自動判定になる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // 自動＝null／数える＝true／数えない＝false
            $table->boolean('count_as_event')->nullable()->after('scale');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('count_as_event');
        });
    }
};
