<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件の編集履歴（先人の要件定義 先-1／2026-08-18）。
 *
 * ねらい：9月から複数人で同じ案件を触るので、「誰がいつ何を何に変えたか」が必ず要る。
 *   例）「集合時間が変わっているけど誰が直した？」を、この表1本で追えるようにする。
 *
 * 記録の入口は App\Support\ProjectHistoryRecorder の1か所だけ。
 * 案件を書き換える画面（案件登録／案件一覧のセル／アサイン表／公開ボード／D決め）は
 * どれも Project モデルの保存を通るので、画面ごとの書き足しは要らない。
 *
 * 1行＝1項目の変更。1回の保存で3項目変われば3行入る（表にしたときに読みやすいため）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_histories', function (Blueprint $table) {
            $table->id();
            $table->string('project_id');                    // どの案件か
            $table->string('project_name')->nullable();      // 参考：そのときの案件名（案件を消しても読めるように）
            $table->string('action', 20)->default('updated'); // created＝新規登録／updated＝変更／deleted＝削除
            $table->string('field')->nullable();             // 変えた項目（DBの列名。action=created/deleted のときは空）
            $table->string('field_label')->nullable();       // 参考：そのときの日本語名（表示用）
            $table->text('old_value')->nullable();           // 変更前（人が読める形）
            $table->text('new_value')->nullable();           // 変更後（人が読める形）
            $table->string('person_id')->nullable();         // 変えた人（people.id・不明なら空）
            $table->string('person_name')->nullable();       // 参考：そのときの氏名（改名しても当時の記録が読める）
            $table->timestamps();

            $table->index(['project_id', 'id']);   // 案件ごとに新しい順で引く
            $table->index('created_at');           // 「今日の変更」を引く
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_histories');
    }
};
