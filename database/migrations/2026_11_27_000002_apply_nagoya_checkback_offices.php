<?php

use App\Models\Project;
use App\Models\ProjectShare;
use App\Support\ProjectHistoryRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 名古屋の過去アサイン表の取込（11/25）について、名古屋（桑江さん）から返ってきた答えを入れる
 * （小沼さん 11/27「なごやからチェックバックきたから確認反映して」）。
 * 元＝確認用スプシ「取り込み後の確認（11月25日）」の G列。
 *
 * 【なぜ要るか】名古屋の表に拠点の印が無かった32行は、東京の案件としてECSに登録されたままだった。
 *   桑江さんの答えで「どこの拠点の案件か」が分かったので、登録拠点と巻き取りの印を直す。
 *   取込では拠点を書きかえない決まり（keepOnUpdate）なので、ここで1回だけ直す。
 *
 * 【直すこと】（小沼さん 11/27 OK）
 *   ・答えが「名古屋」＝登録拠点を名古屋にする（人はそのまま）… 21件
 *   ・答えが「名古屋案件、東京実施」＝登録拠点を名古屋＋東京が巻き取り … 7件（うち1件は東京・大阪）
 *   ・答えが「東京案件、名古屋実施」＝登録拠点はそのまま＋名古屋が巻き取り … 3件
 *   ・8/14 お化け屋敷（P-2026-1276）＝リハ日にする（答え「8/15-23で実施」・小沼さん「リハ日でいれておいて」）
 * 向き＝登録拠点が持ち主、project_shares.office が巻き取る側（正本 PastProjectImportController::crossPlan）。
 *
 * ⚠ 見つからない案件は飛ばす。2回流しても同じ結果になる。down は何もしない（元の拠点は編集履歴に残る）。
 */
return new class extends Migration
{
    private const NAGOYA = '名古屋';

    /** 答えが「名古屋」＝登録拠点だけ名古屋にする。 */
    private const TO_NAGOYA = [
        'P-2024-0264', 'P-2024-0867', 'P-2025-0204', 'P-2025-0207', 'P-2025-0211', 'P-2025-0234',
        'P-2025-0250', 'P-2025-0320', 'P-2025-0321', 'P-2025-0340', 'P-2025-0371', 'P-2025-0372',
        'P-2025-0373', 'P-2025-0375', 'P-2025-0396', 'P-2025-0526', 'P-2025-0777', 'P-2026-0528',
        'P-2026-0773', 'P-2026-0213', 'P-2026-0311',
    ];

    /** 答えが「名古屋案件、東京（・大阪）実施」＝名古屋の案件を、書いてある拠点が巻き取った。 */
    private const NAGOYA_TAKEN_BY = [
        'P-2025-0360' => ['東京'],
        'P-2026-0547' => ['東京'],
        'P-2026-0765' => ['東京'],
        'P-2026-0772' => ['東京'],
        'P-2026-0901' => ['東京', '大阪'],
        'P-2026-0929' => ['東京'],
        'P-2026-0092' => ['東京'],
    ];

    /** 答えが「東京案件、名古屋実施」＝登録拠点はそのまま、名古屋が巻き取った。 */
    private const TAKEN_BY_NAGOYA = ['P-2025-0808', 'P-2026-0557', 'P-2026-0628'];

    /** お化け屋敷：8/14 はリハ日（本番は 8/15〜23）。 */
    private const REHEARSAL = 'P-2026-1276';
    private const REHEARSAL_MAIN = 'P-2026-1277';

    public function up(): void
    {
        if (! Schema::hasTable('projects') || ! Schema::hasTable('project_shares')) {
            return;
        }

        foreach (self::TO_NAGOYA as $id) {
            $this->moveToNagoya($id);
        }
        foreach (self::NAGOYA_TAKEN_BY as $id => $takers) {
            if ($project = $this->moveToNagoya($id)) {
                foreach ($takers as $taker) {
                    $this->share($project, $taker);
                }
            }
        }
        foreach (self::TAKEN_BY_NAGOYA as $id) {
            $project = Project::find($id);
            // 登録拠点がすでに名古屋なら、自分の拠点への印は意味が無いので付けない。
            if ($project && (string) $project->office !== self::NAGOYA) {
                $this->share($project, self::NAGOYA);
            }
        }

        $this->rehearsal();
    }

    private function moveToNagoya(string $id): ?Project
    {
        $project = Project::find($id);
        if (! $project) {
            return null;
        }
        if ((string) $project->office !== self::NAGOYA) {
            $project->office = self::NAGOYA;
            $project->save();   // 編集履歴に「登録拠点」が1行残る
        }
        // 持ち主の拠点への関わりは意味が無いので消す（取込と同じ）。
        ProjectShare::where('project_id', $id)->where('office', self::NAGOYA)->delete();

        return $project;
    }

    private function share(Project $project, string $taker): void
    {
        $had = ProjectShare::where('project_id', $project->id)->where('office', $taker)->first();
        if ($had && (string) $had->kind === '巻き取り') {
            return;
        }
        ProjectShare::updateOrCreate(
            ['project_id' => $project->id, 'office' => $taker],
            ['kind' => '巻き取り']
        );
        ProjectHistoryRecorder::recordNote($project, '拠点間の関わり', $had ? $taker.'（'.$had->kind.'）' : '（なし）', $taker.'（巻き取り）');
    }

    private function rehearsal(): void
    {
        $project = Project::find(self::REHEARSAL);
        $main = Project::find(self::REHEARSAL_MAIN);
        if (! $project) {
            return;
        }
        $project->date_type = 'リハ日';
        if ($main && empty($project->parent_project_id)) {
            // 連日イベントの根っこがあればそこへ、無ければ 8/15 の本番へ結ぶ。
            $project->parent_project_id = $main->parent_project_id ?: $main->id;
        }
        if ($project->isDirty()) {
            $project->save();
        }
    }

    public function down(): void
    {
        // 元の拠点は編集履歴に残っている。自動では戻さない。
    }
};
