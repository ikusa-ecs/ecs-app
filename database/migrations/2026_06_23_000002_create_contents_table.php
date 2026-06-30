<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contents（コンテンツ・マスタ）テーブル。
 * 案件の催し物の種類（水合戦・運動会など）。スタッフの経験回数を数える単位になる（設計書8章）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->string('id')->primary();              // 例: CT-005
            $table->string('content_name');               // コンテンツ名（種類）
            $table->string('category')->nullable();       // 分類（任意：真面目系/盛り上げ系 等）
            $table->boolean('is_physical')->default(false);// 体力系か（現場種別の自動判定に使用）
            $table->boolean('active')->default(true);     // 利用中フラグ
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
