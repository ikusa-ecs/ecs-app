<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件に「運営人数（IKUSA）」を持たせる（2026-09-18 baba要望）。
 *
 * これまで運営人数は1つだけで、その数字が募集・アサイン・スタッフ画面の締切まで全部を動かしていた。
 * 現場には派遣や他社の人も立つので、「全体で何人か」と「そのうちIKUSAが何人か」を分けて残したい。
 *
 * 【決めたこと（2026-09-18 baba）】
 *   ・**全体人数＝これまでの運営人数**（projects.required_count / required_count_min）。
 *     募集・残り◯名・自動アサイン・スタッフ画面の締切は**今までどおりこちらで動く**。
 *     ⚠ ここを IKUSA の人数に付け替えると、スタッフ側の募集締切が静かに変わるのでやらない。
 *   ・**IKUSA の人数はこの新しい列＝覚えておくための数字**（計算には使わない）。
 *
 * 「6〜8」のような幅のある書き方も全体人数と同じようにできるので、列も同じ持ち方にする
 * （max＝多いほう・min＝少ないほう。読み方の正本は App\Support\Headcount）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('ikusa_count')->nullable()->after('required_count_min');
            $table->integer('ikusa_count_min')->nullable()->after('ikusa_count');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['ikusa_count', 'ikusa_count_min']);
        });
    }
};
