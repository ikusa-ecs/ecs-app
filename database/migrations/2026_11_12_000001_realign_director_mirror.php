<?php

use App\Models\Project;
use App\Support\DirectorSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

/**
 * 今日からあとの案件について、D／SD の「写し」（projects.director_id / sd_id）を
 * いまのアサイン（assignments）に合わせ直す（2026-11-12 小沼さん）。
 *
 * 経緯：Dのアサインを日別ボードや取込などで外すと写しが残り、アサイン表と案件一覧に
 *       外したはずの人がDとして出続けていた（12/11 電脳都市に水内さん）。
 *       これからは DirectorSync::queueRefresh が追いかける。ここは、すでにずれているぶんの1回きりの手当て。
 *
 * ⚠ 終わった案件（開催日が今日より前）は触らない＝アサイン作業に関係なく、昔のデータを書き換えないため。
 * ⚠ 列は足さない・消さない。down() ですることは無い（元の値には戻せない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Project::whereNotNull('start_date')
            ->whereDate('start_date', '>=', Carbon::today())
            ->pluck('id')
            ->each(function ($id) {
                try {
                    DirectorSync::refreshMirror((string) $id);
                } catch (\Throwable $e) {
                    report($e);   // 1件の失敗で、ほかの案件と公開の作業を止めない
                }
            });
    }

    public function down(): void
    {
        //
    }
};
