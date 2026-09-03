<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件ごとの「派遣依頼」（2026-09-03 baba要望「派遣で書いた案件が一覧で出るシートを作りたい」）。
 *
 * ⚠ それまで日別ボードの「＋派遣」は**画面の中だけ**の動きで、DBに何も残っていなかった
 *   （assign.blade.php の addHaken が画面の配列に足すだけ）。
 *   ＝押しても読み込み直すと消えるし、「どの案件に派遣を頼んだか」がどこにも無かった。
 *
 * 【1行の意味】1つの案件に対する、1つの派遣先への依頼。
 *   同じ案件で2社に頼むことがあるので、案件×派遣先で複数行になる。
 *
 * ⚠ 名簿（people）には入れない。派遣の方を名簿に入れると、
 *   アサイン表・集計・スタッフ名簿すべてに並んでしまい、同じ人が毎回違うと増え続ける
 *   （2026-09-03 baba決定「案件×派遣の新しい表を作る」）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('project_id')->index();     // 対象の案件
            $table->string('agency');                  // 派遣先（派遣会社名・個人名）
            $table->unsignedSmallInteger('count')->default(1);   // 人数
            $table->string('role')->nullable();        // 役割（受付・FC など。自由記入でよい）
            // 状態＝依頼中／確定／キャンセル。⚠ キャンセルは消さずに残す（頼んだ事実が消えると経緯が追えない）。
            $table->string('status')->default('依頼中');
            $table->string('requested_on', 10)->nullable();   // 依頼した日（'Y-m-d'・分からなければ空）
            $table->text('note')->nullable();          // 備考（返事待ち、条件など）
            $table->string('created_by')->nullable();  // 入れた人（people.id）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_dispatches');
    }
};
