<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 前泊の集合時間（2026-09-15・FBシート No.14 桑江さん）。
 *
 * 【なぜ要るか】
 * 宿泊ありの案件では、スタッフは**前の日**に集まって移動する。
 * これまで集合時間は「イベント当日の集合時間」しか持っていなかったので、
 * 前泊の集合時間は毎回チャットで別に伝えるしかなかった。
 *
 * ⚠ 当日の集合時間（start_time）とは別の列にする。同じ列に入れると
 *   「当日の集合が前の日の時間になっている」という取り違えが必ず起きる。
 * ⚠ 空＝未定（前泊なしの案件では使わない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('stay_pre_meet_time', 20)->nullable()->after('lodging');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('stay_pre_meet_time');
        });
    }
};
