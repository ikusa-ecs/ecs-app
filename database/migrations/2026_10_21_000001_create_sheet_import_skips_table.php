<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサイン表の取込で「取り込まない」にした案件を覚える（2026-10-21 baba「毎回チェック外すのめんどう」）。
 *
 * 例：11/7 小間久＝名古屋の人がECSにだけ入っている＝取り込むたびに「消える人」と出て、毎回外していた。
 * 外したときの差分の中身（指紋）を覚えておき、次も同じ中身なら最初から外しておく。
 * 中身が変わったら（＝アサイン表で何か直った）いつもどおりチェックを入れて見せる。正本＝App\Support\SheetSkipMemory。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_import_skips', function (Blueprint $table) {
            $table->id();
            $table->string('project_id')->unique();       // projects.id（どの案件を外したか）
            $table->string('fingerprint', 32);            // 外したときの差分の指紋（md5）
            $table->string('skipped_by')->nullable();     // 外した人（people.id）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_import_skips');
    }
};
