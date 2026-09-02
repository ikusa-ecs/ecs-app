<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人ごとのメモ（2026-09-02 baba要望）。
 *
 * D決め画面で社員名を押したときのふきだしに書く、その人あてのメモ。
 * 例：「10/3 大型入ってるからアサインしない」
 *
 * 【なぜ people に列を足さないか】
 * ・誰がいつ書いたかを残したい（アサインの判断材料なので、古い情報を見分けたい）。
 * ・あとから「案件ごと」「月ごと」のメモに広げたくなったとき、行を足すだけで済む。
 *
 * 【なぜ settings に入れないか】
 * ⚠ 危険日（DangerDays）のように settings へ JSON でまとめると、
 *   2人が同時に別の人のメモを保存したとき、**後から保存したほうで丸ごと上書き**されて
 *   片方のメモが黙って消える。人ごとに1行にしておけば、その事故が起きない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_notes', function (Blueprint $table) {
            $table->id();
            // 誰あてのメモか（people.id）。⚠ people.id は文字列（E-001 / S-015）。
            $table->string('person_id')->index();
            $table->text('note')->nullable();
            // 誰が最後に書いたか（people.id）。名前は表示のときに引く。
            $table->string('updated_by')->nullable();
            $table->timestamps();

            // 1人1行。⚠ ここが無いと、同時に保存したときに行が増えてメモが二重に見える。
            $table->unique('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_notes');
    }
};
