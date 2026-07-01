<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects に「ケータリングのメモ」列を追加。
 * ケータリングが「無」以外のとき、内容・時間・食数などを自由に書き留めるための欄。
 * 案件一覧の詳細でケータリングを選び、メモを書くとここに保存される（空＝メモ無し）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('catering_note')->nullable()->after('catering');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('catering_note');
        });
    }
};
