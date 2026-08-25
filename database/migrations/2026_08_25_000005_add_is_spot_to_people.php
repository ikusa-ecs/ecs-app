<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 臨時スタッフ（2026-08-25 baba要望）。
 *
 * インターンで今月だけ来る方、誰かの知り合いの助っ人など、
 * **名簿に正式には載っていないけれど現場に入る人**を、その場で名簿に足して
 * アサインできるようにするための印。
 *
 * ⚠ なぜ「アサインに名前を文字で書く」ではなく、名簿に足す形にしたか（baba選択）
 *   アサインは「案件 × 名簿の人 × 日」で1行、という形で全画面がつながっている
 *   （出勤数・稼働状況・同日ダブルブッキング検知・履歴）。名前を文字で書く形にすると
 *   その人だけどの集計にも入らず、同じ人が再び来ても履歴がつながらない。
 *
 * 臨時の人は **ログインしない**（メール・パスワードを持たない）。名簿では「臨時」と分かるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('is_spot')->default(false)->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('is_spot');
        });
    }
};
