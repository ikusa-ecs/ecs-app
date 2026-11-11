<?php

use App\Support\ContentGenre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツの種類（リアル／オンライン）と分類（スプシの分類）を足す（2026-11-11 小沼さん）。正本＝App\Support\ContentGenre。
 * 本番の台帳123件のうち、スプシにあったものだけ最初に入れる（IDと名前の両方が一致したときだけ・空の欄だけ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('category');    // リアル / オンライン
            $table->string('genre')->nullable()->after('kind');       // 謎解き脱出・合戦系 など
        });

        foreach (ContentGenre::INITIAL as $id => [$name, $kind, $genre]) {
            DB::table('contents')->where('id', $id)->where('content_name', $name)->whereNull('kind')
                ->update(['kind' => $kind, 'genre' => $genre]);
        }
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['kind', 'genre']);
        });
    }
};
