<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人のOJT担当（2026-10-09 baba要望「新人とOJTを記載できるところもほしい」）。
 * rookie_ojt_id＝OJT担当の社員（people.id）／rookie_note＝ひとことメモ。新人ページで書く。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('rookie_ojt_id')->nullable();
            $table->string('rookie_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['rookie_ojt_id', 'rookie_note']);
        });
    }
};
