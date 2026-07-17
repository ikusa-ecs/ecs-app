<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 収支入力（イベプラ要望）のDB保存先。案件1件ぶんの収支を1行で持つ。
 * revenue＝売上（受注額）、items＝経費明細（{費目キー: {qty, amount}} のJSON。明細まで保存）、memo＝メモ。
 * これまで localStorage だけだった収支を、開き直しても残る本物の保存にする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_finances', function (Blueprint $table) {
            $table->id();
            $table->string('project_id')->unique();   // projects.id（案件1件＝1行）
            $table->integer('revenue')->nullable();    // 売上（受注額）
            $table->json('items')->nullable();         // 経費明細（費目キー→数量/金額）
            $table->text('memo')->nullable();          // メモ
            $table->string('updated_by')->nullable();  // 入力者（認証接続後に使う。今はnull）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_finances');
    }
};
