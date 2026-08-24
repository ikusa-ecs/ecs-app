<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 名簿に「ふりがな」を足す（2026-08-24 baba要望）。
 *
 * なぜ＝プルダウンを五十音順に並べたいが、漢字の氏名だけでは並べられない
 * （文字コード順になるため「青山」より「渡辺」が先に来ることがある）。
 * 読みを持てば正しく五十音で並べられる。
 *
 * 入力の入口は4つ：初回ログインの初期設定／マイプロフィール／アカウント発行／名簿CSV取込。
 * 空でも動く（空の人は氏名順で末尾側に回る）ので、既存データの移行は不要。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // ひらがな想定（例：やまだ たろう）。カタカナで入っても並びは崩れない。
            $table->string('name_kana')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('name_kana');
        });
    }
};
