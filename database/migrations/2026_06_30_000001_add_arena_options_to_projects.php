<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects に arena_options 列を追加。
 * ARENA場所貸しのとき IKUSA 側で対応する7項目（前日設営・照明設営・MCアサイン・
 * 当日音響照明・レイアウト・配信中継・食事）を「あり/なし」で1つのJSONにまとめて保存する。
 * 項目が今後増減しても列を足さずに済むよう、個別列ではなくJSONで持つ（base_locations と同じ方針）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('arena_options')->nullable()->after('operation_place');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('arena_options');
        });
    }
};
