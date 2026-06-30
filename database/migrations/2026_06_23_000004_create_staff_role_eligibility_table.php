<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_role_eligibility（ポジション可否）。設計書8章 D-6。
 * スタッフ × ポジション（D/OP/MC/FC/CK/軍師/受付）の「できる」を1行ずつ持つ。
 * 候補出しの絞り込みに使う。people テーブルには持たせない（単一の正＝この表）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_role_eligibility', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                    // people.id（スタッフ）
            $table->string('position');                    // D / OP / MC / FC / CK / GUN / UKE
            $table->timestamps();

            $table->unique(['staff_id', 'position']);      // 同じ人×同じ役割は1行だけ
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_role_eligibility');
    }
};
