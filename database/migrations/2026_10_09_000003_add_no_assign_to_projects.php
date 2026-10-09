<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「アサイン不要（IKUSAは運営に入らない）」の印（2026-10-09 baba要望）。
 * 例：会場備え付けのBBQ＝IKUSAのアクティビティではないので、Dもスタッフも要らない。
 * true＝人を入れる画面（日別ボード・D決め・公開ボード・スタッフの募集・自動アサイン）に出さない。
 * 正本＝Project::scopeNeedsAssign。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('no_assign')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('no_assign');
        });
    }
};
