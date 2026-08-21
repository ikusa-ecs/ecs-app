<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スタッフへのお知らせ送信をやめたので、送信記録のテーブルを消す（2026-08-21 baba決定）。
 *
 * 経緯：8/20 に「確定をスタッフに知らせる手段が無い」穴を埋めるために作ったが、
 * 連絡はこれまでどおり LINE で行う方針になったため、画面ごと削除した。
 * ※ 作成時のマイグレーション（2026_08_20_000003）は履歴として残してある。
 *   まっさらから作り直すときは「作って → ここで消す」という流れになる（害はない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('staff_notifications');
    }

    public function down(): void
    {
        // 元に戻す（作成時と同じ形）。機能ごと復活させるときは、画面・処理も作り直しが必要。
        if (Schema::hasTable('staff_notifications')) {
            return;
        }

        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('staff_id');
            $table->string('project_id');
            $table->date('date')->nullable();
            $table->string('dedup_key')->unique();
            $table->string('channel')->default('mail');
            $table->string('to')->nullable();
            $table->string('status')->default('sent');
            $table->string('note')->nullable();
            $table->string('sent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('kind');
            $table->index('staff_id');
            $table->index('project_id');
        });
    }
};
