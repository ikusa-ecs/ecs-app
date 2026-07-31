<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project_shares（案件の拠点間共有）テーブル。全拠点運用の中核（設計書19.2(2)(3)）。
 *
 * 案件は projects に1件のまま（複製しない＝案件情報は共有・自動反映）。
 * このテーブルは「登録拠点ではない拠点が、この案件にどう関わっているか」を1行で記録する。
 *  ・project_id ＝ 対象の案件（おおもと）。
 *  ・office     ＝ 関わっている拠点（＝アサイン担当が「自拠点にコピー」した拠点）。
 *  ・kind       ＝ 種別。'ヘルプ'（人だけ出す）／'巻き取り'（その拠点が運営する）。
 *  ・created_by ＝ 引っぱった人（people.id）。
 *
 * これにより：
 *  ・拠点別の件数＝登録拠点(projects.office) ＋ この共有に載っている拠点、を「関わった」として数える。
 *  ・全社合計＝登録拠点のみ（このテーブルは数えない）。
 *  ・他拠点依頼数＝登録拠点と office と kind から 東→他拠点／他拠点→東／ヘルプ を仕分ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_shares', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');                 // 対象案件（projects.id）
            $table->string('office');                     // 関わっている拠点
            $table->string('kind')->default('ヘルプ');     // ヘルプ / 巻き取り
            $table->string('created_by')->nullable();     // 引っぱった人（people.id）
            $table->timestamps();

            // 同じ案件×同じ拠点は1行だけ（二重コピー防止）。
            $table->unique(['project_id', 'office']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_shares');
    }
};
