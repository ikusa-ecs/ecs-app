<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 謎解きの紙の在庫を「コンテンツ×拠点」で持つ（2026-10-15 baba要望）。
 *
 * ・紙は拠点ごとに別々に置いている（baba回答）＝入庫数も拠点ごとに入れる。
 * ・alerted_at＝在庫が120部を切ったとチャットワークへ知らせた日時（切った時に1回だけ送るため）。
 *   120部以上に戻ったら空に戻す＝また切ったら送る。
 *
 * ⚠ これまでの入庫数（拠点の区別が無い数字）は**消す**（baba回答「いったん空にして入れ直す」）。
 *   どの拠点の紙か分からない数字を残すと、在庫を取り違えるため。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_paper_stocks')->delete();

        Schema::table('content_paper_stocks', function (Blueprint $table) {
            $table->dropUnique(['content_id']);
        });

        Schema::table('content_paper_stocks', function (Blueprint $table) {
            $table->string('office')->default('')->after('content_id');
            $table->timestamp('alerted_at')->nullable()->after('note');
            $table->unique(['content_id', 'office']);   // コンテンツ×拠点で1行
        });
    }

    public function down(): void
    {
        DB::table('content_paper_stocks')->delete();

        Schema::table('content_paper_stocks', function (Blueprint $table) {
            $table->dropUnique(['content_id', 'office']);
        });

        Schema::table('content_paper_stocks', function (Blueprint $table) {
            $table->dropColumn(['office', 'alerted_at']);
            $table->unique('content_id');
        });
    }
};
