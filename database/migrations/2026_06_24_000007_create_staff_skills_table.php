<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_skills（スタッフ保有スキル・中間テーブル）。設計書8章のテーブル一覧に対応。
 * スタッフ（people）× スキル（skills）を結ぶ。必須スキル案件の候補絞り込み（11章）に使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_skills', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                    // people.id（スタッフ）
            $table->unsignedBigInteger('skill_id');        // skills.id（スキル）
            $table->string('note')->nullable();            // 補足（レベル・取得日など・任意）
            $table->timestamps();

            $table->unique(['staff_id', 'skill_id']);      // 同じ人×同じスキルは1行
            $table->index('staff_id');
            $table->index('skill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_skills');
    }
};
