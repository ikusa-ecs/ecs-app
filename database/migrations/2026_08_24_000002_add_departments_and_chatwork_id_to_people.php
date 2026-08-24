<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 名簿に「兼務の所属」と「チャットワークID」を足す（2026-08-24 baba要望）。
 *
 * ① departments（兼務も含めた所属すべて）
 *    所属を兼ねている人がいる。既存の department は「主な所属」として残し、
 *    兼務を含む全部をこの列に持つ（例 ["イベプラ","ARENA"]）。
 *    ・表示・絞り込み・「イベプラかどうか」の判定＝departments（兼務も拾う）
 *    ・集計（部署別の出勤数）＝department（主な所属）で1回だけ数える
 *      ＝両方に数えると合計が人数と合わなくなるため。切り替えたいときは
 *      StatsController の1か所を変えるだけでよい。
 *
 * ② chatwork_id（チャットワークのアカウントID）
 *    いまリマインドの宛先は「ECSの氏名とチャットワークのルームメンバー名の突き合わせ」で
 *    決めている。表記ゆれ（スペース・旧姓・ニックネーム）で照合が外れると
 *    その人にタスクが飛ばない。IDを持てば確実に届く。
 *
 * どちらも空でも動く＝既存データの移行は不要。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // 兼務も含めた所属すべて（配列）。空なら department 1つだけとして扱う。
            $table->json('departments')->nullable()->after('department');
            // チャットワークのアカウントID（数字だが桁が多いので文字列で持つ）。
            $table->string('chatwork_id')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['departments', 'chatwork_id']);
        });
    }
};
