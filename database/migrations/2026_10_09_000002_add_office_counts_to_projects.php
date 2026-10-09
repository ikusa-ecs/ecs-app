<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 拠点ごとの必要人数（2026-10-09 baba要望「日別ボードで東1 名9のように変えられたら最高」）。
 * 例：{"東京":1,"名古屋":9}。null／空＝これまでどおり運営人数（required_count）1つで動く。
 * 正本＝App\Support\OfficeCounts。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('office_counts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('office_counts');
        });
    }
};
