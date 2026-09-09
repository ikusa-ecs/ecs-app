<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 画面の色（テーマ）を人ごとに覚えるための列（2026-09-09 baba要望）。
 *
 * 【なぜ人ごとか】「白っぽいのが好き」「会社カラーのオレンジがいい」と好みが分かれるため。
 * 【なぜ端末ではなくアカウントか】会社のPCでもスマホでも同じ色にするため
 *   （ブラウザに覚えさせると、端末を変えるたびに選び直しになる）。
 *
 * ⚠ 空（null）＝いままでの色。既定を変えたいときは列ではなく
 *   App\Support\Themes::DEFAULT を直す（色の正本はそこ1か所）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('theme', 20)->nullable()->after('office');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
