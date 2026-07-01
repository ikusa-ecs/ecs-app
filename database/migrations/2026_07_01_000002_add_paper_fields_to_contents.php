<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツに「謎解きの紙（印刷物）」の集計用フラグを追加する。
 *   needs_paper      … このコンテンツは紙（謎解きシート）が必要か（在庫集計の対象になる）
 *   sheets_per_team  … 1チームあたり何枚必要か（基本1枚。コンテンツごとに変えられる）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->boolean('needs_paper')->default(false);        // 紙が必要な謎解きか
            $table->unsignedInteger('sheets_per_team')->default(1); // 1チームあたりの枚数
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['needs_paper', 'sheets_per_team']);
        });
    }
};
