<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 収支未入力リマインドの「送信済み」記録。
 * 同じ案件に何度も催促しないための重複防止に使う（人数確定リマインドと同じ考え方）。
 *
 * 収支の締切＝イベント終了後3営業日（2026-08-06 baba確定）。
 * 締切を迎えたのに未入力の案件を拾い、D（ディレクター）へチャットワークでタスクを付ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('dedup_key');                 // 案件ID（1案件につき1回だけ送る）
            $table->string('project_id')->nullable();    // 参考：どの案件か
            $table->date('event_date')->nullable();      // 参考：イベント開催日
            $table->date('deadline')->nullable();        // 参考：入力の締切（3営業日後）
            $table->string('room_id')->nullable();       // 送信先ルーム（本番/テストの区別）
            $table->timestamp('sent_at')->nullable();    // 送信した日時
            $table->timestamps();

            $table->unique('dedup_key');   // 同じ案件へ二度送らない
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reminder_logs');
    }
};
