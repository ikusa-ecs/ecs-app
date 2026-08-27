<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Googleカレンダー連携の「同期の待ち行列」（2026-08-27 baba要望）。
 *
 * 【なぜ待ち行列にするか（大事）】
 * ⚠ 案件やDが変わる入口は今とても多い。
 *   Dが変わるだけで5か所（D決め画面／案件一覧のセル／保守コマンド／取込×2）、
 *   日程が変わるのが3か所、さらにキャンセル・削除・アーカイブはそれぞれ別の処理。
 *   保存のたびにカレンダーへ送る作りにすると、**必ずどこかを書き忘れて古い予定が残る**。
 *
 * そこで「保存されたら**印を付けるだけ**」にして、あとでまとめてカレンダーを直す。
 * 印を付ける入口は Project／Assignment の保存イベント1か所ずつなので漏れない。
 * （編集履歴＝ProjectHistoryRecorder が同じ形で動いていて、実績のある型）
 *
 * 【もう1つの利点】
 * Googleの通信が失敗しても、ECSの保存は絶対に止まらない（別のタイミングで送るため）。
 * 失敗の理由は last_error に残して画面で見られるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_syncs', function (Blueprint $table) {
            $table->id();

            // どの案件の予定か。1案件＝1予定なので重複禁止。
            $table->string('project_id')->unique();

            // Googleカレンダー側の予定ID。空＝まだ作っていない。
            // ⚠ これを覚えておかないと、直すときに「どの予定か」が分からず新しく増える。
            $table->string('google_event_id')->nullable();

            // 直す必要があるか（保存イベントが立てる印）。
            $table->boolean('needs_sync')->default(true);

            // 消す必要があるか（案件がキャンセル・削除・アーカイブされたとき）。
            // ⚠ 削除は「消してから印を付ける」ことができないので、案件が消える前に立てる。
            $table->boolean('needs_delete')->default(false);

            // 最後にGoogleへ送れた日時と、そのとき作った予定名（画面で見て確かめるため）。
            $table->timestamp('synced_at')->nullable();
            $table->string('synced_title')->nullable();

            // 失敗したときの理由（画面に出す）。成功したら空に戻す。
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index('needs_sync');
            $table->index('needs_delete');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_syncs');
    }
};
