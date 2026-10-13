<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 編集履歴に「どこから変えたか」（2026-10-13 baba「どのページのどの部分か分かるようにしてほしい」）。
 * 例）「アサイン表 › 案件の時間・人数・備考」。付け方の正本＝App\Support\HistorySource。
 * ⚠ これより前の履歴は空のまま（記録が無いので後から埋められない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_histories') && ! Schema::hasColumn('project_histories', 'source')) {
            Schema::table('project_histories', function (Blueprint $table) {
                $table->string('source', 200)->nullable()->after('person_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_histories', 'source')) {
            Schema::table('project_histories', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
