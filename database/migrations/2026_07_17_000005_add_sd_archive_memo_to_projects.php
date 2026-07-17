<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件一覧の詳細セル編集・手動アーカイブ・公開ボードの備考を保存できるようにする列。
 * ・sd_id：SD担当（people参照）。これまで「未設定」固定だった。
 * ・is_archived：手動アーカイブの状態。null=自動（開催日で判定）／true=手動で隠す／false=手動で戻す。
 * ・publish_memo：スタッフ公開ボードの「💬備考」（担当メモ）。これまで localStorage だった。
 * （director_id/goods_owner_id/transport/audio_equipment は既存列を使う）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('sd_id')->nullable()->after('director_id');
            $table->boolean('is_archived')->nullable()->after('status');
            $table->text('publish_memo')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['sd_id', 'is_archived', 'publish_memo']);
        });
    }
};
