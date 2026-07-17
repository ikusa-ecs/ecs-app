<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * マイページの「通知設定」を本人ごとにDB保存する列。
 * これまで localStorage だけだった通知オン/オフ（新人フォロー所感・アサイン確定・締切）を people に持たせる。
 * 中身は {follow:bool, assign:bool, deadline:bool} のJSON。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('notify_settings')->nullable()->after('planner_impression');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('notify_settings');
        });
    }
};
