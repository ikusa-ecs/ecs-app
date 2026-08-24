<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * アサイン画面（案件詳細）/assign-detail。
 *
 * 【2026-07-17 入口を本物へ寄せた（baba 承認）】
 * 保存できる本物のアサインは /project-assign（手動アサイン）に一本化した。この画面は
 * その「入口」として振る舞う：
 *   ・?case=<案件ID> 付き（日別ボード・公開ボードの「詳細→」等）→ /project-assign へ自動転送。
 *   ・?case なし（サイドバーから）→ 「アサインする案件を選ぶ」一覧を表示（各行から本物画面へ）。
 * これで見本（保存されない編集）と本物が混在して迷う状態を解消する。
 *
 * ※2026-08-24：残っていた旧・見本の画面（resources/views/assign_detail.blade.php）と
 *   それを描く legacyShow() を削除した。どこからも呼ばれていないのに
 *   「押しても保存しないボタン」（候補を再提案／アサインを確定＋CSV／スタッフに公開）が
 *   入っていたため、検索で見つけて誤解する・復活させる元になっていた。
 *   この画面が使うテンプレートは assign_pick.blade.php だけ。
 */
class AssignDetailController extends Controller
{
    public function show(Request $request)
    {
        $id = (string) $request->query('case', '');

        // ① 案件が指定されていて実在するなら、本物のアサイン画面へそのまま転送。
        if ($id !== '' && Project::whereKey($id)->exists()) {
            return redirect('/project-assign?project=' . urlencode($id));
        }

        // ② 案件指定なし（または見つからない）＝「アサインする案件を選ぶ」一覧を表示。
        $today = Carbon::today();
        $projects = Project::with('director:id,name')
            ->whereNotNull('start_date')
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date && $p->start_date->gte($today))
            ->values();

        // アサイン済み人数（キャンセル以外）を案件ごとに集計＝一覧で進捗が見えるように。
        $assignedCount = Assignment::whereIn('project_id', $projects->pluck('id'))
            ->where('status', '!=', 'キャンセル')
            ->select('project_id', DB::raw('count(distinct staff_id) as c'))
            ->groupBy('project_id')
            ->pluck('c', 'project_id');

        $cases = $projects->map(function (Project $p) use ($today, $assignedCount) {
            $off = intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400);

            return [
                'id' => $p->id,
                'name' => $p->project_name ?: '（名称未定）',
                'client' => $p->client ?? '',
                'date' => $p->start_date->format('Y-m-d'),
                'dow' => ['日', '月', '火', '水', '木', '金', '土'][(int) $p->start_date->dayOfWeek],
                'off' => $off,
                'cat' => $p->site_category ?: '通常',
                'need' => (int) ($p->required_count ?? 0),
                'done' => (int) ($assignedCount[$p->id] ?? 0),
                'dir' => optional($p->director)->name ?? '未定',
                'place' => $p->location ?? '',
            ];
        })->all();

        return view('assign_pick', ['cases' => $cases]);
    }
}
