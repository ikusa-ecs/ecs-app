<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects に「追加案件として公開した日」extra_published_at を追加。
 * スタッフ公開ボードで「追加案件にする」をオンにした日を記録し、
 * スタッフ画面の締切「公開日＋3日（土日なら月曜）」の起点に使う。
 * 通常案件では使わない（null のまま）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('extra_published_at')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('extra_published_at');
        });
    }
};
