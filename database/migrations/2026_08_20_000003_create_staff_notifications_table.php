<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スタッフへ送った通知の記録（重複送信の防止＋「誰にいつ送ったか」の証跡）。
 *
 * ねらい：これまでスタッフへの通知は一切実装が無く、公開・確定を伝える手段が
 *   LINE／チャットワークの手作業だけだった。ECSから送れるようにするが、
 *   **自動送信はしない**（2026-08-20 baba 決定＝社員が画面で相手と文面を確かめてから送る）。
 *   同じ知らせを二度送らないよう、dedup_key で1回に限る。
 *
 * dedup_key の作り方＝ kind + 案件ID + スタッフID（+ 日付）。
 * status … sent（送った）／skipped（宛先がダミー等で送らなかった）／failed（送信エラー）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind');                    // assign_confirmed / project_published
            $table->string('staff_id');                // people.id（宛先）
            $table->string('project_id');              // projects.id
            $table->date('date')->nullable();          // アサインの日（案件公開の知らせでは null）
            $table->string('dedup_key')->unique();     // 同じ知らせを二度送らないための鍵
            $table->string('channel')->default('mail'); // 送り方（今はメールだけ）
            $table->string('to')->nullable();          // 実際の宛先（記録用）
            $table->string('status')->default('sent'); // sent / skipped / failed
            $table->string('note')->nullable();        // skipped/failed の理由
            $table->string('sent_by')->nullable();     // 送信を実行した人（people.id）
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('kind');
            $table->index('staff_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
