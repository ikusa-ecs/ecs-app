<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 謎解きの紙の追加発注（2026-10-15 baba要望）。
 *
 * 「◯部 発注した（いつ届く予定）」を残し、届いたら「届いた」を押す＝その拠点の入庫数に足す。
 * arrived_on が空＝発注中。発注中のあいだは、120部の知らせに「発注中◯部あり」と添える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_orders', function (Blueprint $table) {
            $table->id();
            $table->string('content_id');                 // contents.id
            $table->string('office');                     // どの拠点の紙か
            $table->integer('count');                     // 発注した部数
            $table->date('ordered_on');                   // 発注した日
            $table->date('expected_on')->nullable();      // 届く予定日（任意）
            $table->date('arrived_on')->nullable();       // 届いた日（空＝発注中）
            $table->string('note')->nullable();           // メモ（例：第3刷・印刷会社名）
            $table->string('ordered_by')->nullable();     // 記録した人の名前
            $table->string('arrived_by')->nullable();     // 「届いた」を押した人の名前
            $table->timestamps();

            $table->index(['office', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_orders');
    }
};
