<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Googleカレンダーに直接登録する（2026-11-02 baba決定「ボタン1つでGoogleカレンダーに書き込む方式」）。
 *
 * google_calendar_links … 人ごとのGoogleとのつながり。refresh_token＝本人が「許可」したときにもらう鍵（暗号化して保存）。
 * google_calendar_events … ECSが登録した予定の控え（uid＝案件ID-役割-種類 → Google側の予定ID）。
 *   もう一度押したとき、同じ予定は上書きする（重ねない）／アサインが外れた先の予定は消す、に使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('google_calendar_links')) {
            Schema::create('google_calendar_links', function (Blueprint $table) {
                $table->string('person_id')->primary();
                $table->text('refresh_token');
                $table->string('google_email')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('google_calendar_events')) {
            Schema::create('google_calendar_events', function (Blueprint $table) {
                $table->id();
                $table->string('person_id');
                $table->string('uid');
                $table->string('event_id');
                $table->date('case_date');   // 本番の日（先の予定だけ消すのに使う）
                $table->timestamps();
                $table->unique(['person_id', 'uid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_events');
        Schema::dropIfExists('google_calendar_links');
    }
};
