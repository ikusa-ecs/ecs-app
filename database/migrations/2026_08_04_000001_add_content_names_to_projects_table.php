<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件に「案件名（コンテンツ）として入力された名前そのもの」を持たせる。
 *
 * これまで案件はコンテンツを content_ids（コンテンツ台帳の番号）だけで覚えていた。
 * そのため、台帳に登録しないコンテンツ（その案件限りの単発）は行き場が無かった。
 *
 * この列に入力された名前をそのまま残しておくことで、
 * 「台帳には登録しないが、案件には名前が残る」ができるようになる。
 * 既存の案件は空のまま（読むときは今までどおり content_ids から名前を復元する）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('content_names')->nullable()->after('content_ids');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('content_names');
        });
    }
};
