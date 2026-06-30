<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_role_experience（ポジション経験）。設計書8章に対応。
 * スタッフが各ポジション（役割）を何回経験したかを持つ。
 * role は assignments.role と同じ区分（D/OP/MC/FC/CK/GUN=軍師・サポーター/UKE=受付）。
 * auto_count＝確定アサインから自動集計／manual_adjust＝手動補正。
 * 合計（total）は保存せず、モデルで auto+manual を都度計算する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_role_experience', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                    // people.id（スタッフ）
            $table->string('role');                        // D/OP/MC/FC/CK/GUN/UKE
            $table->integer('auto_count')->default(0);     // 確定アサインから自動集計した回数
            $table->integer('manual_adjust')->default(0);  // 手動補正（導入前経験など。加算）
            $table->date('last_date')->nullable();         // 直近に経験した日
            $table->timestamps();

            $table->unique(['staff_id', 'role']);          // 同じ人×同じ役割は1行
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_role_experience');
    }
};
