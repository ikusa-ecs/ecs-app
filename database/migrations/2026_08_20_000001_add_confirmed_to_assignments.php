<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assignments に「いつ・誰が確定したか」を足す。
 *
 * これまで確定（status='確定'）にした記録が残らなかった：
 *   - assigned_by は全画面で null 固定＝誰がアサインしたか分からない
 *   - 確定日時は assigned_at を流用していたため、仮→確定に上げても時刻が動かず
 *     アサインダッシュボードの「確定日時」が実際は「最初にアサインした時刻」だった
 *
 * confirmed_at / confirmed_by は「仮 → 確定」に変わった瞬間だけ記録し、
 * 確定を仮に戻したら空に戻す（App\Support\AssignmentStamp の1か所で扱う）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('assigned_at');   // 確定にした日時
            $table->string('confirmed_by')->nullable()->after('confirmed_at');     // 確定にした人（people.id）
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'confirmed_by']);
        });
    }
};
