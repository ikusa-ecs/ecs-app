<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * people に「事務所（office）」を追加する。
 * 所属する地域オフィス＝東京/大阪/名古屋/福岡/東北/北海道。
 * 社員・スタッフ共通の項目（people は1テーブルなので両方に効く）。
 * 既存の department（イベプラ等の担当部署）とは別概念。
 * ※ 列を1つ足すだけ＝既存データは消えない（migrate:fresh は不要）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('office')->nullable();   // 事務所（地域オフィス）。未設定は null。
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('office');
        });
    }
};
