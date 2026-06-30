<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * skills（スキルマスタ）。設計書8章のテーブル一覧に対応（スキル・資格の定義）。
 * 例＝防災士・救命講習・運転免許・手話 など。staff_skills 経由でスタッフに紐づく。
 * ※設計書では一覧に1行説明のみ（詳細列は未定義）。他マスタの作法に合わせた最小構成。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('skill_name');                  // スキル・資格名
            $table->string('category')->nullable();        // 分類（資格／技能 など・任意）
            $table->boolean('active')->default(true);      // 利用中フラグ
            $table->timestamps();

            $table->unique('skill_name');                  // 同名のスキルは1件
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
