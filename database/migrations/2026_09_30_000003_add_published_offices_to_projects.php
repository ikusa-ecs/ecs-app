<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スタッフへの公開を「拠点ごと」にする（2026-09-30 baba要望「スタッフ公開ボードは拠点ごとにしてほしい」）。
 *
 * 【これまで】公開は案件ごとに1つ（staff_published）。公開すると、登録拠点にもヘルプ・巻き取りで関わる拠点にも、
 *   全部のスタッフに出ていた（名古屋が巻き取った案件を名古屋で公開すると、東京のスタッフにも出る 等）。
 * 【これから】published_offices＝どの拠点のスタッフに公開しているか（例 ["名古屋","東京"]）。
 *   ・staff_published は「どこかの拠点で公開中」の意味で残す（自動アサインの対象・集計などはこれを見る）。
 *   ・⚠ null＝これまでどおり「関わる全拠点に公開」（今もう公開している案件が急に見えなくならないように）。
 *     次に公開ボードで押したときから拠点ごとになる。正本＝App\Support\OfficePublish。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('published_offices')->nullable()->after('staff_published');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('published_offices');
        });
    }
};
