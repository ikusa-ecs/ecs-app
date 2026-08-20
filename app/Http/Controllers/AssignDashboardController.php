<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * アサインダッシュボード（/assign-dashboard）。
 *
 * アサイン担当者向けの「状況まとめ」画面。すべて本物のデータ（DB）から作る：
 *  ・数値サマリ（募集中／今週の確定／希望0件／平均稼働率）
 *  ・アサインが必要な案件（行クリックで日別ボードへジャンプ）
 *  ・希望充足の要注意スタッフ（希望はあるのにアサインが少ない人）
 *  ・直近の確定アサイン（確定したものを案件ごと・確定日時の新しい順）
 *
 * 稼働率・希望日数の定義は稼働状況（StaffStatusController）と同じものを使い、画面間で数字がブレないようにする。
 * 対象月は「今日の当月」（例：7月に開けば2026-07）。運用ではアサインMTGの対象月にあたる。
 */
class AssignDashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        // 対象月＝今日の当月（当月の1日〜末日）。period は '2026-07' の形の月キー。
        $period = $today->format('Y-m');
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        // 案件ごとの「決まっている人数」＝assignments のうちキャンセル以外の実人数（同じ人が
        // 複数日に出ても1人と数える）。案件IDごとに一度だけ集計して引けるようにする。
        $filledByProject = Assignment::where('status', '!=', 'キャンセル')
            ->select('project_id', DB::raw('COUNT(DISTINCT staff_id) AS cnt'))
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        // 案件ID → date_type（本番/予備日/リハ等）。稼働率は本番のみ数えるため。
        $projectType = Project::pluck('date_type', 'id');

        // ── アサインが必要な案件（未着手・調整中・これから先の開催）──────────────
        $needProjects = Project::whereIn('status', ['未着手', '調整中'])
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $today)
            ->orderBy('start_date')
            ->get();

        $needAssign = $needProjects->map(function (Project $p) use ($filledByProject) {
            $need = $p->required_count;
            $filled = (int) ($filledByProject[$p->id] ?? 0);

            // 状況：まだスタッフに公開していない案件は「非公開」を優先して出す。
            if (! $p->staff_published) {
                $statusLabel = '非公開';
                $statusBadge = 'gray';
            } else {
                $statusLabel = $p->status;
                $statusBadge = $p->status === '調整中' ? 'blue' : 'amber';
            }

            return [
                'id'      => $p->id,
                'name'    => $p->project_name ?: '（名称未定）',
                'yomi'    => $p->yomi ?: '—',
                'date'    => $p->start_date->format('n/j'),
                'need'    => $need,
                'filled'  => $filled,
                'fillCls' => $need && $filled >= $need ? 'ok' : ($filled > 0 ? 'mid' : 'low'),
                'status'  => $statusLabel,
                'badge'   => $statusBadge,
            ];
        })->values();

        // ── 数値サマリ：募集中の案件 ──────────────────────────────────────
        // 募集中＝スタッフに公開中（staff_published=ON）。うち「未確定」＝決定人数<必要人数。
        $published = Project::where('staff_published', true)->get();
        $recruitCount = $published->count();
        $recruitUndecided = $published->filter(function (Project $p) use ($filledByProject) {
            $need = (int) $p->required_count;
            $filled = (int) ($filledByProject[$p->id] ?? 0);

            return $need > 0 && $filled < $need;
        })->count();

        // ── 数値サマリ：今週の確定案件 ────────────────────────────────────
        // 今週開催で status=確定 の案件数。サブの「のべ◯名」＝その案件群の非キャンセルのアサイン延べ行数。
        $weekStart = $today->copy()->startOfWeek();
        $weekEnd = $today->copy()->endOfWeek();
        $weekConfirmed = Project::where('status', '確定')
            ->whereBetween('start_date', [$weekStart, $weekEnd])
            ->get();
        $weekConfirmedCount = $weekConfirmed->count();
        $weekManDays = $weekConfirmedCount
            ? Assignment::where('status', '!=', 'キャンセル')
                ->whereIn('project_id', $weekConfirmed->pluck('id'))
                ->count()
            : 0;

        // ── 数値サマリ：希望0件 ＆ 平均稼働率 ＆ 要注意スタッフ ──────────────
        // 稼働状況画面と同じ定義：稼働率＝今月の本番アサイン数 ÷ 対象月の希望日数（希望0件は対象外）。
        $assignsByStaff = Assignment::all()->groupBy('staff_id');
        $prefByStaff = ShiftPreference::where('period', $period)->available()->get()->groupBy('staff_id');

        $rates = [];
        $zeroPrefCount = 0;
        $alerts = [];
        foreach (Person::staff()->get() as $p) {
            $want = $prefByStaff->get($p->id, collect())->count();
            if ($want === 0) {
                $zeroPrefCount++;
                continue; // 希望0件は稼働率・要注意の対象外（別枠で数える）
            }

            $month = $assignsByStaff->get($p->id, collect())
                ->filter(function (Assignment $a) use ($monthStart, $monthEnd, $projectType) {
                    return $a->date
                        && $a->date->between($monthStart, $monthEnd)
                        && $projectType->get($a->project_id) === '本番';
                })->count();

            $rate = (int) round($month / $want * 100);
            $rates[] = $rate;

            // 要注意＝希望はあるのに充足率30%未満（30%担保の目安に未達）。0%＝赤、1〜29%＝オレンジ。
            if ($rate < 30) {
                $total = (int) ($p->experience_count ?? 0);
                $alerts[] = [
                    'name'  => $p->name,
                    'lv'    => $total <= 10 ? '新人' : ($total < 30 ? '中堅' : 'ベテラン'),
                    'want'  => $want,
                    'month' => $month,
                    'rate'  => $rate,
                    'level' => $rate === 0 ? 'danger' : 'warn',
                ];
            }
        }
        $avgRate = count($rates) ? (int) round(array_sum($rates) / count($rates)) : null;

        // 要注意は深刻な順（充足率の低い順）に並べる。
        usort($alerts, fn ($a, $b) => $a['rate'] <=> $b['rate']);

        // ── 直近の確定アサイン（確定したものを案件ごとにまとめ、確定日時の新しい順）──────
        $confirmedByProject = Assignment::where('status', '確定')->get()
            ->groupBy('project_id')
            ->map(fn ($rows) => [
                'project_id'  => $rows->first()->project_id,
                'headcount'   => $rows->pluck('staff_id')->unique()->count(),
                // 確定日時＝確定にした時刻（confirmed_at）。古い行は記録が無いので
                // 従来どおり assigned_at で代用する（2026-08-20 に confirmed_at を追加）。
                'confirmedAt' => $rows->max(fn ($r) => $r->confirmed_at ?? $r->assigned_at),
            ])
            ->sortByDesc('confirmedAt')
            ->take(6)
            ->values();

        $projForConfirm = Project::whereIn('id', $confirmedByProject->pluck('project_id'))->get()->keyBy('id');
        $recentConfirmed = $confirmedByProject->map(function ($c) use ($projForConfirm) {
            $p = $projForConfirm->get($c['project_id']);

            return [
                'confirmedAt' => $c['confirmedAt'] ? $c['confirmedAt']->format('n/j H:i') : '—',
                'name'        => $p && $p->project_name ? $p->project_name : '（名称未定）',
                'date'        => $p && $p->start_date ? $p->start_date->format('n/j') : '—',
                'headcount'   => $c['headcount'],
            ];
        });

        // ── 気にかけたい人（稼働状況の「気にかけたい人」カードをここへ移動）──────────
        // 指標は稼働状況（StaffStatusController::buildStatus）と同じ単一ソースを使い、画面間でブレさせない。
        $statusList = app(StaffStatusController::class)->buildStatus();
        $pickRate = fn ($s) => $s['applied'] > 0 ? (int) round($s['picked'] / $s['applied'] * 100) : null;

        $careZero = $statusList->filter(fn ($s) => $s['zeroPref'])->pluck('name')->all();
        $careRenkin = $statusList->filter(fn ($s) => $s['renkin'] >= 4)
            ->map(fn ($s) => ['name' => $s['name'], 'renkin' => $s['renkin']])->values()->all();
        $careLowRate = $statusList->filter(fn ($s) => $s['rate'] !== null && $s['rate'] > 0 && $s['rate'] < 30)
            ->map(fn ($s) => ['name' => $s['name'], 'rate' => $s['rate']])->values()->all();
        $carePick = $statusList->filter(fn ($s) => $s['applied'] >= 4 && $pickRate($s) !== null && $pickRate($s) < 30)
            ->map(fn ($s) => ['name' => $s['name'], 'rate' => $pickRate($s), 'picked' => $s['picked'], 'applied' => $s['applied']])->values()->all();
        $careGobusata = $statusList->filter(fn ($s) => $s['lastDays'] !== null && $s['lastDays'] >= 30)
            ->sortByDesc('lastDays')
            ->map(fn ($s) => ['name' => $s['name'], 'days' => $s['lastDays']])->values()->all();

        return view('assign_dashboard', [
            'needAssign'       => $needAssign,
            'recruitCount'     => $recruitCount,
            'recruitUndecided' => $recruitUndecided,
            'weekConfirmed'    => $weekConfirmedCount,
            'weekManDays'      => $weekManDays,
            'zeroPref'         => $zeroPrefCount,
            'avgRate'          => $avgRate,
            'alerts'           => $alerts,
            'recentConfirmed'  => $recentConfirmed,
            'careZero'         => $careZero,
            'careRenkin'       => $careRenkin,
            'careLowRate'      => $careLowRate,
            'carePick'         => $carePick,
            'careGobusata'     => $careGobusata,
        ]);
    }

    /**
     * 「アサインが必要な案件」の一覧をCSVでダウンロードさせる（/assign-dashboard/export.csv）。
     *
     * 対象・並びは index() と同じ（未着手・調整中／これから先の開催／開催日順）。
     * 列＝開催日／案件名／クライアント／必要人数／確定数／不足数（必要−確定）。
     * Excelでの文字化けを防ぐため UTF-8 BOM を先頭に付ける。
     */
    public function exportCsv()
    {
        $today = Carbon::today();

        // index() と同じ「決まっている人数」（キャンセル以外の実人数）を案件IDごとに集計。
        $filledByProject = Assignment::where('status', '!=', 'キャンセル')
            ->select('project_id', DB::raw('COUNT(DISTINCT staff_id) AS cnt'))
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        // index() と同じ対象・並び（未着手・調整中／これから先／開催日順）。
        $needProjects = Project::whereIn('status', ['未着手', '調整中'])
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $today)
            ->orderBy('start_date')
            ->get();

        $rows = $needProjects->map(function (Project $p) use ($filledByProject) {
            $need = (int) $p->required_count;
            $filled = (int) ($filledByProject[$p->id] ?? 0);

            return [
                $p->start_date ? $p->start_date->format('Y/n/j') : '',
                $p->project_name ?: '（名称未定）',
                $p->client ?: '',
                $need,
                $filled,
                $need - $filled,   // 不足数（必要−確定。マイナスは充足済み）
            ];
        });

        // BOM＋ヘッダ行＋データ行を組み立て。fputcsv がカンマ・"・改行を正しくエスケープする。
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($handle, ['開催日', '案件名', 'クライアント', '必要人数', '確定数', '不足数']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'assign-needs_' . now()->format('Y-m') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
