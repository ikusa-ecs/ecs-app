<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人数確定リマインドの「送信済み」記録。
 * 同じ案件（開催日×コンテンツ×クライアント）に二度送らないための重複防止に使う。
 * GAS版の「送信ログ」タブに相当。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_deadline_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('dedup_key');                 // 開催日|コンテンツ|クライアント（重複判定キー）
            $table->string('project_id')->nullable();    // 参考：どの案件か
            $table->date('event_date')->nullable();      // 参考：イベント開催日
            $table->string('room_id')->nullable();       // 送信先ルーム（本番/テストの区別）
            $table->timestamp('sent_at')->nullable();    // 送信した日時
            $table->timestamps();

            $table->unique('dedup_key');   // 同じキーは1回だけ（重複送信を防ぐ）
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_deadline_reminder_logs');
    }
};
