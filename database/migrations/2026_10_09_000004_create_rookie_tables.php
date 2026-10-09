<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人ページ（2026-10-09 baba要望）。
 *
 * ・content_difficulties … 「コンテンツ難易度」シート（リアル／オンライン）の1行＝1件。
 *   シートの名前とECSの台帳の名前が違うことが多い（ジャンサバ⇔ジャングルサバイバル等）ので、
 *   どの台帳のコンテンツか（content_id）を画面でつなぐ。つながっていない行は使わない。
 * ・people.rookie_state … null＝自動（入社2年以内のイベプラ・セールスは新人として出る）／
 *   'in'＝手で新人に入れた／'out'＝卒業（独り立ち）。
 * ・people.rookie_since … 何ヶ月目かを数える起点（空なら入社日）。
 * 正本＝App\Support\Rookies・App\Support\RookieFcPlan。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_difficulties', function (Blueprint $table) {
            $table->id();
            $table->string('kind');                     // リアル / オンライン
            $table->string('sheet_name');               // シートに書かれたコンテンツ名
            $table->string('category')->nullable();     // シートの左の分類（謎解き脱出・合戦系 など）
            $table->unsignedTinyInteger('difficulty')->nullable();   // 1〜4
            $table->boolean('must')->default(false);    // 新人D必修
            $table->boolean('recommend')->default(false);   // 新人D推奨
            $table->boolean('one_of')->default(false);  // ◻︎▲★＝同じ分類の中から1つでよい
            $table->string('content_id')->nullable();   // つないだECSのコンテンツ（contents.id）
            $table->timestamps();
            $table->unique(['kind', 'sheet_name']);
            $table->index('content_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->string('rookie_state', 10)->nullable();
            $table->date('rookie_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_difficulties');
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['rookie_state', 'rookie_since']);
        });
    }
};
