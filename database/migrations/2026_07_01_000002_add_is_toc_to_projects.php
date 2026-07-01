<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects に「toC（一般消費者向け）」フラグを追加。
 * 企業向け(toB)ではなく、一般のお客様向けのイベントかどうかを記録する。
 * 案件登録フォームのチェックで on/off、一覧の絞り込みにも使う。既定は false（＝toB扱い）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_toc')->default(false)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_toc');
        });
    }
};
