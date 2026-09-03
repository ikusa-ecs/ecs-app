<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人ごとのメモを「その日ごと」にする（2026-09-03 baba報告）。
 *
 * 【何が起きていたか】
 * ⚠ メモを1人1行で持っていたため、「10/3 大型入ってるからアサインしない」と書くと
 *   **カレンダーの全部の日にそのメモが出て**しまい、かえって分からなくなっていた。
 *   もともとの用例（「10/3 …」）からして、メモは**その日についてのもの**だった。
 *
 * 【どう変えたか】
 * ・日付（date）を足して、1人×1日で1行にする。
 * ・⚠ 昨日までに書いた「日付なし」のメモは、消さずに残すが**画面には出さない**
 *   （どの日のことか分からないため）。必要なら日付を付けて書き直してもらう。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_notes', function (Blueprint $table) {
            // どの日についてのメモか（'2026-10-03'）。⚠ 古い行は null＝画面に出さない。
            $table->string('date', 10)->nullable()->after('person_id')->index();
        });

        // 1人1行 → 1人×1日で1行。⚠ ここが無いと、同じ日に二重で行ができてメモが二つ見える。
        Schema::table('person_notes', function (Blueprint $table) {
            $table->dropUnique('person_notes_person_id_unique');
        });
        Schema::table('person_notes', function (Blueprint $table) {
            $table->unique(['person_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('person_notes', function (Blueprint $table) {
            $table->dropUnique(['person_id', 'date']);
        });
        Schema::table('person_notes', function (Blueprint $table) {
            $table->dropColumn('date');
        });
        Schema::table('person_notes', function (Blueprint $table) {
            $table->unique('person_id');
        });
    }
};
