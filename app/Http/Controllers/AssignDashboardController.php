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
 * 対象月は当面 2026-07 固定（アサインMTGの対象月）。
 */
class AssignDashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $monthStart = Carbon::create(2026, 7, 1)->startOfDay();
        $monthEnd = Carbon::create(2026, 7, 31)->endOfDay();

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
        $prefByStaff = ShiftPreference::where('period', '2026-07')->available()->get()->groupBy('staff_id');

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
                'confirmedAt' => $rows->max('assigned_at'),
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
        ]);
    }
}
