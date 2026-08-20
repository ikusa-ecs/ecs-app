<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件に「スタッフ本人に伝えること」を足す。
 *
 * これまでスタッフ画面の確定アサインには 日付・案件名・時間・集合形式・会場 しか出せず、
 * 持ち物・服装・当日の注意事項は DB の項目自体が無かった（＝当日必要な情報が本人に届かない）。
 *
 *  - staff_belongings … 持ち物（例：黒スーツ、スニーカー、モバイルバッテリー）
 *  - staff_dresscode  … 服装（例：黒スーツ／私服可・上下黒）
 *  - staff_notes      … 当日の注意事項（自由記入）
 *  - assembly_detail  … 集合場所の詳しい説明（集合形式のプルダウンでは書けない住所・目印・ゲート名など）
 *
 * 入れる場所＝案件登録フォーム（セールス）と公開ボード（アサイン担当）の両方。
 * 保存は projects なので、案件の編集履歴（ProjectFieldLabels）にも載せる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('assembly_detail')->nullable()->after('assembly_type');   // 集合場所の詳細
            $table->text('staff_belongings')->nullable()->after('assembly_detail');  // 持ち物
            $table->string('staff_dresscode')->nullable()->after('staff_belongings'); // 服装
            $table->text('staff_notes')->nullable()->after('staff_dresscode');       // 当日の注意事項
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['assembly_detail', 'staff_belongings', 'staff_dresscode', 'staff_notes']);
        });
    }
};
