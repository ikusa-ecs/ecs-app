<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Support\FinanceAccess;
use App\Support\FinanceItems;
use App\Support\OfficeScope;
use App\Support\PersonalCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 収支一覧（/finance-list）。2026-08-06 baba確定で新設。
 *
 * ねらい＝収支入力（/mypage-finance）は案件を1件ずつ開く形だったので、
 * 「月の案件が1行ずつ並び、売上・経費・利益と“入力済みか”が見える」表を用意する。
 * 目的は社内で粗利を把握すること（＋Salesforceへ登録するための元データ）。
 *
 * 決まっているルール：
 *  ・見る　＝社員以上は全案件の収支を見られる（tier:employee のグループに置く）。
 *  ・直す　＝担当のD／営業担当と管理者以上だけ（FinanceAccess::canEdit）。一覧では鉛筆リンクの出し分けに使う。
 *  ・拠点　＝他の画面と同じ（一般社員は自拠点／管理者以上は全拠点＋切替スイッチ）。
 *  ・締切　＝イベント終了後3営業日（FinanceAccess::deadline）。過ぎて未入力なら「遅れ」を出す。
 */
class FinanceListController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->build($request);

        return view('finance_list', $data);
    }

    /** 表示中の月ぶんをCSVで書き出す（Excel・Salesforceへの登録用）。 */
    public function exportCsv(Request $request): StreamedResponse
    {
        $data = $this->build($request);
        $rows = $data['rows'];
        $month = $data['selectedMonth'];

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ecs_shushi_' . $month . '.csv"',
        ];

        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // Excelで開いたときに日本語が化けないようにBOMを付ける（他のCSV出力と同じ作り）。
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                '案件ID', '開催日', '案件名', 'クライアント', '拠点',
                'ディレクター', '営業担当', '売上', '経費', '利益',
                '入力状態', '入力締切', '最終入力者', '最終更新',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['date'], $r['name'], $r['client'], $r['office'],
                    $r['director'], $r['sales'], $r['revenue'], $r['cost'], $r['profit'],
                    $r['filled'] ? '入力済み' : ($r['overdue'] ? '未入力（締切超え）' : '未入力'),
                    $r['deadline'], $r['updatedBy'], $r['updatedAt'],
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    /**
     * 一覧の中身を組み立てる（画面とCSVで同じものを使う）。
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $today = Carbon::today();
        $me = PersonalCases::meModel();

        // 拠点の表示範囲（他画面と同じ共通部品）。null＝全拠点。
        $office = OfficeScope::filter($request);

        // 対象＝下書き以外・開催日がある案件（拠点で絞る）。
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date && ($p->status ?? '') !== '下書き')
            ->values();

        // 月の選択肢（案件のある年月だけ）＝アサイン表と同じ作り。
        $months = $projects
            ->map(fn (Project $p) => $p->start_date->format('Y-m'))
            ->unique()->sort()->values()
            ->map(fn (string $ym) => [
                'value' => $ym,
                'label' => substr($ym, 0, 4) . '年' . (int) substr($ym, 5, 2) . '月',
            ]);

        // 表示する月＝?month= があればそれ、無ければ「今月」（無ければ一番新しい月）。
        $monthValues = $months->pluck('value')->all();
        $requested = (string) $request->query('month', '');
        $current = $today->format('Y-m');
        if (in_array($requested, $monthValues, true)) {
            $selectedMonth = $requested;
        } elseif (in_array($current, $monthValues, true)) {
            $selectedMonth = $current;
        } else {
            $selectedMonth = end($monthValues) ?: $current;
        }

        $monthProjects = $projects
            ->filter(fn (Project $p) => $p->start_date->format('Y-m') === $selectedMonth)
            ->values();

        $rows = $this->rows($monthProjects, $me, $today);

        // 月の合計（売上・経費・利益）と入力状況。
        $summary = [
            'count' => $rows->count(),
            'filled' => $rows->where('filled', true)->count(),
            'unfilled' => $rows->where('filled', false)->count(),
            'overdue' => $rows->where('overdue', true)->count(),
            'revenue' => (int) $rows->sum('revenue'),
            'cost' => (int) $rows->sum('cost'),
            'profit' => (int) $rows->sum('profit'),
        ];
        // 粗利率（％・売上が0なら null）。小数1桁。
        $summary['margin'] = $summary['revenue'] > 0
            ? round($summary['profit'] / $summary['revenue'] * 100, 1)
            : null;

        return [
            'rows' => $rows,
            'summary' => $summary,
            'months' => $months,
            'selectedMonth' => $selectedMonth,
            'officeScope' => $office,
            'deadlineBizDays' => FinanceAccess::DEADLINE_BIZ_DAYS,
        ];
    }

    /**
     * 案件1件＝1行に詰め替える。
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $projects, ?Person $me, Carbon $today): Collection
    {
        if ($projects->isEmpty()) {
            return collect();
        }

        $ids = $projects->pluck('id')->all();

        // 保存済みの収支（案件ID→行）。
        $finances = ProjectFinance::whereIn('project_id', $ids)->get()->keyBy('project_id');

        // D（ディレクター）＝assignments(role='D'・キャンセル除く)。表示は氏名。
        $employeeNames = Person::employees()->pluck('name', 'id');
        $directorByProject = Assignment::whereIn('project_id', $ids)
            ->where('role', 'D')
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $employeeNames[$rows->first()->staff_id] ?? '');

        $contentNames = Content::pluck('content_name', 'id');

        // 最終入力者の氏名（people の全員から引く＝社員でもスタッフでも名前が出るように）。
        $allNames = Person::pluck('name', 'id');

        return $projects->map(function (Project $p) use ($finances, $directorByProject, $contentNames, $allNames, $me, $today) {
            $fin = $finances->get($p->id);
            $revenue = $fin->revenue ?? null;
            $items = $fin->items ?? [];
            $cost = FinanceItems::costTotal($items);
            $filled = FinanceItems::isFilled($revenue, $items);

            $deadline = FinanceAccess::deadline($p);
            // 「遅れ」＝締切を過ぎているのに未入力（締切当日はまだ遅れにしない）。
            $overdue = ! $filled && $deadline && $today->gt($deadline);

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $name = $firstContentId
                ? ($contentNames[$firstContentId] ?? $p->project_name)
                : $p->project_name;

            $sales = is_array($p->sales_owners) ? implode('・', array_filter($p->sales_owners)) : '';

            return [
                'id' => $p->id,
                'date' => $p->start_date->format('Y-m-d'),
                'dateLabel' => $p->start_date->format('n/j')
                    . '(' . ['日', '月', '火', '水', '木', '金', '土'][$p->start_date->dayOfWeek] . ')',
                'name' => $name,
                'projectName' => $p->project_name,
                'client' => $p->client ?? '',
                'office' => $p->office ?? '',
                'dayType' => $p->date_type ?? '本番',
                'status' => $p->status ?? '',
                'director' => $directorByProject->get($p->id, ''),
                'sales' => $sales,
                'revenue' => (int) ($revenue ?? 0),
                'hasRevenue' => $revenue !== null,
                'cost' => $cost,
                'profit' => FinanceItems::profit($revenue, $items),
                'filled' => $filled,
                'overdue' => (bool) $overdue,
                'deadline' => $deadline?->format('Y-m-d') ?? '',
                'deadlineLabel' => $deadline?->format('n/j') ?? '—',
                'memo' => $fin->memo ?? '',
                'updatedBy' => $fin && $fin->updated_by ? ($allNames[$fin->updated_by] ?? $fin->updated_by) : '',
                'updatedAt' => $fin?->updated_at?->format('Y-m-d H:i') ?? '',
                // 鉛筆（入力）リンクを出すか＝担当本人か管理者以上か。
                'canEdit' => FinanceAccess::canEdit($p, $me),
            ];
        })->values();
    }
}
