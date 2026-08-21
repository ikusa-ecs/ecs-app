<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 準備チェックに「LINE作成」「LINEダブチェ」を足す（2026-08-21 baba要望）。
 *
 * すでにある準備チェック＝prep_line_sent（LINE概要送付）／prep_handover（引き継ぎ）／prep_script（台本）。
 * 実務では、そのLINEを「作った」段階と「もう一人が確認した（ダブルチェック）」段階も追いたいので2つ足す。
 * どちらも案件一覧の詳細でチェックを付け外しでき、その場で保存される。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('prep_line_created')->default(false)->after('prep_line_sent');       // LINE作成
            $table->boolean('prep_line_double_check')->default(false)->after('prep_line_created'); // LINEダブチェ
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['prep_line_created', 'prep_line_double_check']);
        });
    }
};
