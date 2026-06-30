<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_content_experience（コンテンツ経験）。設計書8章に対応。
 * スタッフが各コンテンツ（運動会・水合戦 等）を何回経験したかを持つ。
 * auto_count＝確定アサインから自動集計／manual_adjust＝導入前経験などの手動補正。
 * 合計（total）は保存せず、モデルで auto+manual を都度計算する（古い値を残さない方針）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_content_experience', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                    // people.id（スタッフ）
            $table->string('content_id');                  // contents.id（コンテンツ）
            $table->integer('auto_count')->default(0);     // 確定アサインから自動集計した回数
            $table->integer('manual_adjust')->default(0);  // 手動補正（導入前経験など。加算）
            $table->date('last_date')->nullable();         // 直近に経験した日
            $table->timestamps();

            $table->unique(['staff_id', 'content_id']);    // 同じ人×同じコンテンツは1行
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_content_experience');
    }
};
