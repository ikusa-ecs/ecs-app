<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 謎解きの紙（印刷物）の在庫。コンテンツごとに「入庫数（手入力）」を保存する。
 * 必要数・消費数は案件データから毎回自動計算するので持たない。
 * 入庫数だけは人が入れる情報なので、ここに残して集計ボタンを押しても消えないようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_paper_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('content_id');                   // contents.id（CT-005 等）
            $table->integer('received_count')->default(0);  // 入庫数（手入力の累計）
            $table->string('note')->nullable();             // メモ（任意）
            $table->timestamps();

            $table->unique('content_id');   // コンテンツ1つにつき1行
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_paper_stocks');
    }
};
