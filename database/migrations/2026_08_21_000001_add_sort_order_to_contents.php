<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツに「並び順」を持たせる（2026-08-21 baba要望）。
 *
 * これまでは ID順（＝登録した順）に固定で、社内で使う順番に並べ替えられなかった。
 * 拠点（offices）と同じ作りにして、マスタ管理の▲▼で並べ替えられるようにする。
 * 既存のコンテンツには、今の並び（ID順）をそのまま10刻みで入れる＝見え方は変わらない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('category');
        });

        // 既存分に今の並び（ID順）を10刻みで振る。間を空けておくと後から挿し込みやすい。
        $order = 0;
        foreach (DB::table('contents')->orderBy('id')->pluck('id') as $id) {
            $order += 10;
            DB::table('contents')->where('id', $id)->update(['sort_order' => $order]);
        }
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
