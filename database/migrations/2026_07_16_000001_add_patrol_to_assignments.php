<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサインに「巡回数」を追加。note（担当＝軍師／サポ等）と対にして、
 * 誰が何回まわるか（巡回）を数値で持てるようにする。
 * コンテンツ側の必要人数リスト（content_role_requirements）が note＋patrol の2本立てなので、それに揃える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->integer('patrol')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('patrol');
        });
    }
};
