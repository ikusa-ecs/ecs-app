<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * applications（案件応募）。設計書8章 applications に対応。
 * 「スタッフ × 案件」を1行で持つ＝本人が個別案件に応募したデータの単一ソース。
 * 月単位の希望（shift_preferences）とは別物で、希望入力(B)の案件応募モードに対応。
 *
 * ・選ばれた率（公平性指標）の元：applied＝応募件数、picked＝そのうち assignments に
 *   実際に入った件数。両テーブルを staff_id×project_id で突き合わせて算出する。
 * ・外部キー制約は付けず index と unique のみ（assignments / shift_preferences と統一）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                 // people.id（スタッフ）
            $table->string('project_id');               // projects.id（応募先の案件）
            $table->string('intent');                   // 希望 / 可
            $table->text('note')->nullable();           // 条件付き応募の一言（例「都内なら可」）
            $table->timestamp('applied_at')->nullable();// 応募日時
            $table->timestamps();

            // 同じ人×同じ案件は1行だけ（応募し直しは上書き）
            $table->unique(['staff_id', 'project_id']);
            $table->index('staff_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
