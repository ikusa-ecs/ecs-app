<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人のFC案で「この案件は外す」（2026-11-11 小沼さん「微妙だったら外して別のが見たい」）。
 * 外した案件のID（連日のイベントは1日目のID）の並び。FCの案はこれを避けて組み直す。正本＝App\Support\RookieFcPlan。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('rookie_skips')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('rookie_skips');
        });
    }
};
