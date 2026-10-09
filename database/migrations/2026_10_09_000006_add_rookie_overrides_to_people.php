<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人の経験を手で直す（2026-10-09 baba「大型で受付だったから実は経験していない、みたいなことがある」）。
 * 例 {"CT-005":{"fc":"none"},"CT-012":{"d":"done"}}。done＝やったことにする／none＝やっていないことにする。
 * 正本＝App\Support\RookieFcPlan。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('rookie_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('rookie_overrides');
        });
    }
};
