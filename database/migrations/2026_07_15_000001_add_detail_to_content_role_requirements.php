<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 必要ポジション枠に「備考・巡回・並び順」を追加し、1ポジション複数枠を持てるように
 * unique(content_id, scale, position) を外す（例：FCが備考違いで複数行）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_role_requirements', function (Blueprint $table) {
            $table->string('note')->nullable()->after('count');        // 備考（担当・役どころ）
            $table->integer('patrol')->nullable()->after('note');      // 巡回（担当チーム数など）
            $table->integer('sort_order')->default(0)->after('patrol'); // 表示・投入順
        });

        // 1ポジション＝1行の制約を解除（同一 position を備考違いで複数持てるように）
        Schema::table('content_role_requirements', function (Blueprint $table) {
            $table->dropUnique(['content_id', 'scale', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('content_role_requirements', function (Blueprint $table) {
            $table->unique(['content_id', 'scale', 'position']);
        });
        Schema::table('content_role_requirements', function (Blueprint $table) {
            $table->dropColumn(['note', 'patrol', 'sort_order']);
        });
    }
};
