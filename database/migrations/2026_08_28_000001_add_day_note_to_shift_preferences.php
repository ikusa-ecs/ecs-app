<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出勤可能日に「その日のメモ」を足す（2026-08-28 baba要望）。
 *
 * 【なぜ列を分けるか】
 * すでにある `note` は**その月ぜんぶの備考**として使っている
 * （同じ文をその月の全部の行に入れて、読むときに1つ拾う作り）。
 * ここに日ごとのメモを混ぜると、月の備考が日ごとにバラバラになって読めなくなる。
 * なので `day_note`（その日だけのメモ）を別に持つ。
 *
 * 使い分け：
 *   note     … その月の備考（「今月は3件くらい入りたい」など）
 *   day_note … その日のメモ（「午後だけ可」「前泊」「ECSにまだ無い予定」など）
 *
 * ⚠ `availability`（〇×△）を空でも保存できるようにする。
 *   〇×△は付けずにメモだけ書きたい日があるため（例：平日の私用）。
 *   数える側は「稼働可 / 希望」だけを見ているので、空が増えても集計は変わらない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_preferences', function (Blueprint $table) {
            $table->text('day_note')->nullable()->after('note');
        });

        Schema::table('shift_preferences', function (Blueprint $table) {
            $table->string('availability')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shift_preferences', function (Blueprint $table) {
            $table->dropColumn('day_note');
        });
    }
};
