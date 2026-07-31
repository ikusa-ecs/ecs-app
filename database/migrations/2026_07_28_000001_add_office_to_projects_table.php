<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * projects に「登録拠点（office）」を追加する。
 *
 * 全拠点運用の土台（設計書19.2）。案件がどの拠点のものかを1つに確定させる列。
 * ・登録拠点＝案件を登録した人の拠点（＝依頼元）。集計・表示・拠点またぎ共有の基準になる。
 * ・これまで拠点は実施形態(format)の文字から推測していたが、この列で正しく持つ。
 * ・既存の案件は「東京」で一括で埋める（現状は東京のみ運用のため＝設計書19.2(5)移行ルール）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // format(実施形態) の直後あたりに置きたいが、SQLite/MySQL両対応のため末尾追加でよい。
            $table->string('office')->nullable()->after('format');
        });

        // 既存案件は現状すべて東京運用なので東京で埋める（未設定を残さない）。
        DB::table('projects')->whereNull('office')->update(['office' => '東京']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('office');
        });
    }
};
