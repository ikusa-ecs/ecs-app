<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * マイプロフィールに聞く項目を増やす（2026-08-31 baba要望）。
 *
 * 【なぜ要るか】
 * アサインを決めるときに「この人に任せられるか」を判断する材料が足りない。
 * とくに ①車を出せるか ②英語や外国語で対応できるか ③本人がやってみたい役割は何か
 * ④オンライン案件で使えるツールは何か、は今まで人づてに聞くしかなかった。
 * 本人に一度入れてもらえば、名簿を見るだけで分かるようにする。
 *
 * 【追加する列】
 * ・other_languages      … 英語以外に話せる言語（自由記入）
 * ・challenge_positions  … これから挑戦してみたい役割（チェックボックス＝複数）
 * ・online_tools         … 日常で使っているオンラインツール（チェックボックス＝複数）
 * ・online_tools_other   … 上の一覧に無いツール（自由記入）
 * ・profile_note         … その他備考（自由記入）
 *
 * ⚠ 運転（driving_level）と英語（english_level）は **もともと people にある列**なので足さない。
 *   これまでスタッフ画面の設定タブにしか入力欄が無かっただけ＝画面側に欄を足す。
 * ⚠ 複数選ぶ2つは json（Laravel の array キャスト）。SQLite でも MySQL でも同じように動く。
 *   別テーブルにしない理由＝選んだ順も集計も要らない「本人の申告」だから。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('other_languages')->nullable()->after('english_level');
            $table->json('challenge_positions')->nullable()->after('other_languages');
            $table->json('online_tools')->nullable()->after('challenge_positions');
            $table->string('online_tools_other')->nullable()->after('online_tools');
            $table->text('profile_note')->nullable()->after('online_tools_other');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn([
                'other_languages',
                'challenge_positions',
                'online_tools',
                'online_tools_other',
                'profile_note',
            ]);
        });
    }
};
