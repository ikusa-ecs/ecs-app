<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shift_preferences（稼働希望）。設計書8章 shift_preferences に対応。
 * 「スタッフ × 1日」を1行で持つ＝本人が翌月の各日について出した可否の単一ソース。
 * ここが埋まると、稼働率（＝アサイン日数 ÷ 希望日数）や活性度区分（その月の
 * 案件開催日数に対するエントリー率）を全画面が同じデータで計算できる。
 *
 * ・希望日数 ＝ その月で availability が「稼働可」または「希望」の行数。これが稼働率の分母。
 * ・desired_count（本人申告の希望件数）は設計書にあるが持たない方針（2026-06-24 baba確定）。
 *   理由＝実際に何件アサインできるかは案件次第で確約できず、自己申告件数は使わないため。
 *   分母は availability の行数から数えるので困らない。
 * ・外部キー制約は付けず index と unique のみ（assignments 等と統一）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                 // people.id（スタッフ）
            $table->string('period');                   // 対象の年月（例 2026-07）
            $table->date('date');                       // 対象の日
            $table->string('availability');             // 稼働可 / 希望 / NG / 未定
            $table->text('note')->nullable();           // 備考
            $table->timestamps();

            // 同じ人×同じ日は1行だけ（入力し直しは上書き）
            $table->unique(['staff_id', 'date']);
            $table->index('staff_id');
            $table->index('period');                    // 月ごとの集計を引きやすく
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_preferences');
    }
};
