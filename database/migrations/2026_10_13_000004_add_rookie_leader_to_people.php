<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人のチームリーダー（2026-10-13 baba「新人一覧でOJT担当のほかにチームリーダーも選べるように」）。
 * rookie_leader_id＝チームリーダーの社員（people.id）。新人ページでOJT担当と並べて選ぶ。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('people', 'rookie_leader_id')) {
            Schema::table('people', function (Blueprint $table) {
                $table->string('rookie_leader_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('people', 'rookie_leader_id')) {
            Schema::table('people', function (Blueprint $table) {
                $table->dropColumn('rookie_leader_id');
            });
        }
    }
};
