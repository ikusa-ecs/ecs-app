<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「この案件はキャンセルになった」印（2026-08-26 baba要望）。
 *
 * 【なぜ列を足すのか】
 * これまでキャンセルになった案件は「アーカイブ（隠す）」で片づけていたが、
 *   ・アーカイブは案件一覧とスタッフ画面しか見ていないため、アサイン表・D決め・公開ボードには**出たまま**
 *   ・「終わった案件」と「中止になった案件」が同じ扱いで、あとから見て区別できない
 * という2つの困りごとがあった。
 *
 * 【なぜ status（未着手/調整中/確定/完了）に足さないのか】
 * status はアサインの進み方（登録 → 調整中 → 確定 → 完了）を表していて、画面の分岐が
 * この4つの前提でできている。ここに「キャンセル」を混ぜると、進み方と中止が同じ列に入って
 * 分岐が壊れやすい。**別の印にすれば、元の状態（どこまで進んでいたか）も残る。**
 * 実施形態（リアル／オンライン等）も**消さずに残す**＝表示だけ「キャンセル」に差し替える。
 *
 * ⚠ 記録は消さない（削除ではない）。イベント数には数えない（App\Support\EventCount）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_cancelled')->default(false)->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('is_cancelled');
        });
    }
};
