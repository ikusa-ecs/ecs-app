<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assignments（アサイン）。設計書8章 assignments に対応。
 * 「案件 × スタッフ × 日」を1行で持つ＝割り当ての単一ソース。
 * ここが埋まると、稼働状況（今月のアサイン数・連勤・ご無沙汰）や
 * 同日ダブルブッキング検知（R-3）を全画面が同じデータで判定できる。
 *
 * ・複数日案件（本番/予備日/リハ）は date を分けて1日1行で持つ。回数は本番のみ数える＝
 *   集計時に projects.date_type で絞る（このテーブルには日付をそのまま持つ）。
 * ・「1日1か所」の重複は警告で扱う方針（ハード制約にしない）ため、DBの外部キー制約は付けず
 *   既存テーブル（staff_role_eligibility 等）と同じく index と unique のみにする。
 * ・assigned_by（操作者）は認証がMTG後のため今は nullable。認証導入後に必須化する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');                  // projects.id（案件）
            $table->string('staff_id');                    // people.id（スタッフ）
            $table->date('date');                          // 対象日（複数日案件は日ごとに1行）
            $table->string('role')->nullable();            // ポジション D/OP/MC/FC/CK/GUN/UKE
            $table->string('status')->default('仮');       // 仮 / 確定 / キャンセル
            $table->decimal('score', 6, 2)->nullable();    // 提案時スコア（自動アサインで使用）
            $table->string('assigned_by')->nullable();     // アサイン操作者（認証導入後に必須化）
            $table->timestamp('assigned_at')->nullable();  // アサイン日時
            $table->timestamps();

            // 同じ案件×同じ人×同じ日は1行だけ（取消は status=キャンセルで表す）
            $table->unique(['project_id', 'staff_id', 'date']);
            $table->index('project_id');
            $table->index('staff_id');
            $table->index('date');                         // 同日ダブルブッキング検知の引きやすさ
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
