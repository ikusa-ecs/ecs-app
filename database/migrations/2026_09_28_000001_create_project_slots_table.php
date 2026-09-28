<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサイン表の「まだ人が決まっていない枠」（2026-09-28 baba要望）。
 *
 * 【なぜ新しい置き場所が要るのか】
 *   いまの assignments は「案件×人×日」で1行＝**人が決まっていないと1行も作れない**。
 *   ところが現場のアサイン表では、人を決める前に
 *     「ここはOP」「ここはIKUSAマスト」「ここは派遣でOK」
 *   と枠だけ先に書いている。これを ECS でもできるようにする。
 *
 * 【assignments と混ぜない理由】
 *   「誰がアサインされているか」の正本は assignments ひとつに保つ
 *   （ここにも staff_id を置くと、必ず食い違って「消えた／出ない」になる）。
 *   このテーブルは**人が決まる前の予定だけ**を持つ。人が決まったら枠は消す。
 *
 * ⚠ 並び順（no）は「枠どうしの順番」であって、画面のNOそのものではない。
 *   画面のNOは、アサイン済みの人・派遣・枠をポジション順に並べ直してから振る
 *   （正本＝App\Support\AssignSlots::rows）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_slots', function (Blueprint $table) {
            $table->id();
            $table->string('project_id')->index();                  // 対象の案件
            $table->unsignedSmallInteger('no')->default(1);          // 枠どうしの並び順
            $table->string('role')->nullable();                      // ポジション（D/MC/OP…）
            $table->string('note')->nullable();                      // 担当メモ（軍師/サポ 等）
            $table->unsignedSmallInteger('patrol')->nullable();      // 巡回数
            $table->text('remark')->nullable();                      // 備考（IKUSAマスト／派遣でOK 等）
            // 「人は未定だが、誰が出すかは決まっている」ときの印。いまは「イベプラ」だけ。
            // ⚠ 文字の正本＝App\Support\AssignSlots::PLACEHOLDERS。
            $table->string('placeholder')->nullable();
            $table->string('created_by')->nullable();                // 入れた人（people.id）
            $table->timestamps();

            $table->unique(['project_id', 'no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_slots');
    }
};
