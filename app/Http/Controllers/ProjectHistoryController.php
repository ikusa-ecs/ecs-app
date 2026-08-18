<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectHistory;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 案件の編集履歴（/project-history）。先人の要件定義 先-1（2026-08-18）。
 *
 * ねらい：9月から複数人で同じ案件を触るので、「集合時間が変わっているけど誰が直した？」に
 *   その場で答えられるようにする。案件編集画面の下にも新しい20件を出しているが、
 *   ここは「案件をまたいで・古いところまで」追うための画面。
 *
 * 見える範囲は拠点で絞る（全拠点運用・設計書19.2）。他拠点の案件の履歴は出さない。
 * この画面は見るだけ。履歴は消せない（消せると記録の意味が無くなるため）。
 */
class ProjectHistoryController extends Controller
{
    /** 1ページに出す件数。 */
    private const PER_PAGE = 100;

    /** 期間の選択肢＝ラベル => さかのぼる日数（null＝全期間）。 */
    private const PERIODS = [
        '7'   => '直近7日',
        '30'  => '直近30日',
        '90'  => '直近90日',
        'all' => 'すべて',
    ];

    public function index(Request $request)
    {
        $projectId = trim((string) $request->query('project', ''));
        $personId  = trim((string) $request->query('person', ''));
        $period    = (string) $request->query('period', '30');
        if (! isset(self::PERIODS[$period])) {
            $period = '30';
        }

        // 見てよい案件のID（拠点で絞る）。管理者以上は全拠点なので null＝絞らない。
        $officeScope = OfficeScope::filter($request);
        $visibleIds = $officeScope === null
            ? null
            : OfficeScope::applyToProjects(Project::query(), $officeScope)->pluck('id')->all();

        $query = ProjectHistory::query()->orderByDesc('id');

        if ($visibleIds !== null) {
            $query->whereIn('project_id', $visibleIds);
        }
        if ($projectId !== '') {
            $query->where('project_id', $projectId);
        }
        if ($personId !== '') {
            $query->where('person_id', $personId);
        }
        if ($period !== 'all') {
            $query->where('created_at', '>=', Carbon::today()->subDays((int) $period));
        }

        $histories = $query->paginate(self::PER_PAGE)->withQueryString();

        // 絞り込みプルダウンの選択肢は「履歴に実際に出てくる人・案件」だけにする
        // （名簿全員を並べると、一度も触っていない人まで出て選びにくいため）。
        $scopeQuery = fn () => ProjectHistory::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('project_id', $visibleIds));

        $peopleOptions = $scopeQuery()
            ->whereNotNull('person_id')
            ->select('person_id', 'person_name')
            ->distinct()
            ->orderBy('person_name')
            ->get()
            ->unique('person_id')
            ->values();

        $projectOptions = $scopeQuery()
            ->select('project_id', 'project_name')
            ->distinct()
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->unique('project_id')
            ->values();

        // 表示中の案件名（?project= で1件に絞ったときの見出し用）。
        $projectLabel = $projectId !== ''
            ? (optional(Project::find($projectId))->project_name ?: $projectId)
            : null;

        return view('project_history', [
            'histories'      => $histories,
            'projectId'      => $projectId,
            'projectLabel'   => $projectLabel,
            'personId'       => $personId,
            'period'         => $period,
            'periods'        => self::PERIODS,
            'peopleOptions'  => $peopleOptions,
            'projectOptions' => $projectOptions,
        ]);
    }
}
