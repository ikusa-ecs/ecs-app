<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * サイズ一覧（/sizes）を見られるスタッフの印（2026-11-02 スタッフ要望「常勤が靴と服のサイズだけ見られるページ」）。
 * 社員以上はこの印が無くても見られる。スタッフは、社員がスタッフ名簿の詳細でチェックした人だけ。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('people', 'can_view_sizes')) {
            Schema::table('people', function (Blueprint $table) {
                $table->boolean('can_view_sizes')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('people', 'can_view_sizes')) {
            Schema::table('people', function (Blueprint $table) {
                $table->dropColumn('can_view_sizes');
            });
        }
    }
};
