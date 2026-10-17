<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人の「参加したいイベント」（2026-10-17 baba要望）。
 * 中身＝コンテンツ台帳のIDの並び（["CT-1","CT-5"]）。新人ページで入れる。
 * FCの案（App\Support\RookieFcPlan）で、希望のコンテンツなら「まだやっていない」と同じくらい加点する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('rookie_wishes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('rookie_wishes');
        });
    }
};
