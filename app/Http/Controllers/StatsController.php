<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 集計ダッシュボード（/stats）。
 *
 * ねらい：これまで無かった「経営・運営の全体集計」を1画面にまとめる（baba 2026-07-24）。
 *  ・イベント数（拠点ごと／オンライン・リアル）
 *  ・メンバー全員のイベント出勤数（ランキング）
 *  ・部署（イベプラ／セールス）の合計出勤数
 * 期間は「月／四半期／年」で切り替える。
 *
 * データ元：イベント＝projects（開催日あり・下書き/中止を除く）。
 *           出勤＝assignments（キャンセル以外）。1人が参加した“イベント数”＝重複しない案件数で数える。
 * 拠点・種別（オンライン/リアル）は実施形態(format)の生テキストから読み取る（ダッシュボードと同じ考え方）。
 */
class StatsController extends Controller
{
    /** 拠点（事務所）の表示順。実データが無くても常にこの順で全拠点を出す。 */
    private const OFFICE_ORDER = ['東京', '名古屋', '大阪', '福岡', '北海道', '東北'];

    public function index(Request $request)
    {
        // 集計対象のイベント＝開催日があり、下書き・中止でない案件（完了＝過去実績は含める）。
        $projects = Project::whereNotNull('start_date')
            ->whereNotIn('status', ['下書き', 'キャンセル', '中止'])
            ->get(['id', 'start_date', 'format', 'base_locations', 'scale']);

        // 期間の選択肢を「月／四半期／年」それぞれで作る（案件のある期間だけ）。
        $spanKeys = [
            'month'   => $this->periodOptions($projects, 'month'),
            'quarter' => $this->periodOptions($projects, 'quarter'),
            'year'    => $this->periodOptions($projects, 'year'),
        ];

        // 選ばれた粒度（既定＝月）。
        $span = (string) $request->query('span', 'month');
        if (! in_array($span, ['month', 'quarter', 'year'], true)) {
            $span = 'month';
        }
        $options = $spanKeys[$span];
        $optionValues = array_column($options, 'value');

        // 選ばれた期間。無ければ「今の期間（あれば）／無ければ最新」。
        $requested = (string) $request->query('period', '');
        $nowKey = $this->periodKey(Carbon::today(), $span);
        if (in_array($requested, $optionValues, true)) {
            $selected = $requested;
        } elseif (in_array($nowKey, $optionValues, true)) {
            $selected = $nowKey;
        } else {
            $selected = end($optionValues) ?: '';
        }

        // 選んだ期間の案件だけに絞る。
        $inPeriod = $projects->filter(fn (Project $p) => $this->periodKey($p->start_date, $span) === $selected)->values();

        // ── イベント数の集計 ──
        $totalEvents = $inPeriod->count();
        $onlineEvents = $inPeriod->filter(fn (Project $p) => $this->isOnline($p->format))->count();
        $realEvents = $totalEvents - $onlineEvents;

        // 拠点（事務所）別イベント数。各案件は1つの拠点だけに数える＝拠点別の合計＝totalEvents（二重計上しない）。
        // 定番拠点（東京〜東北）は0でも常に表示。当てはまらない案件は末尾に「他拠点（未指定）／その他」で出す。
        // ※ 現状は東京のみ運営で対象拠点データが空。他拠点対応は今後の宿題（baba 2026-07-24）。
        $officeCounts = [];
        $officeBig = [];   // うち大型（scale='大型'）の件数
        foreach (self::OFFICE_ORDER as $o) {
            $officeCounts[$o] = 0;
            $officeBig[$o] = 0;
        }
        foreach ($inPeriod as $p) {
            $o = $this->officeOf($p);
            $officeCounts[$o] = ($officeCounts[$o] ?? 0) + 1;
            if ($p->scale === '大型') {
                $officeBig[$o] = ($officeBig[$o] ?? 0) + 1;
            }
        }
        // 定番拠点（東京〜東北）は0件でも常に表示する（baba 2026-07-24）。当てはまらない案件は末尾に出る。
        $byOffice = collect($officeCounts)
            ->map(fn ($cnt, $office) => ['office' => $office, 'count' => $cnt, 'big' => $officeBig[$office] ?? 0])
            ->values();

        // ── 出勤数の集計 ──（この期間の案件に対するアサイン・キャンセル除く）
        $projectIds = $inPeriod->pluck('id');
        $assignments = $projectIds->isEmpty()
            ? collect()
            : Assignment::whereIn('project_id', $projectIds)
                ->where('status', '!=', 'キャンセル')
                ->get(['staff_id', 'project_id']);

        // 出勤数＝アサインされた日数ぶん（同じ案件で複数日あれば、その日数だけ数える・baba 2026-07-24）。
        // assignments は「案件×人×日」で1行なので、行数がそのまま出勤日数になる。
        $countByStaff = $assignments
            ->groupBy('staff_id')
            ->map(fn (Collection $g) => $g->count());

        // うち大型（scale='大型'の案件）の出勤日数。案件ID→大型かの集合で判定する。
        $bigProjectIds = $inPeriod->filter(fn (Project $p) => $p->scale === '大型')->pluck('id')->flip();
        $bigCountByStaff = $assignments
            ->groupBy('staff_id')
            ->map(fn (Collection $g) => $g->filter(fn ($a) => isset($bigProjectIds[$a->project_id]))->count());

        // 関係する人の氏名・種別・部署をまとめて引く。
        $people = Person::whereIn('id', $countByStaff->keys()->all())
            ->get(['id', 'name', 'role', 'department', 'office'])
            ->keyBy('id');

        // メンバー別ランキング（出勤数の多い順）。
        $members = $countByStaff
            ->map(function ($count, $staffId) use ($people, $bigCountByStaff) {
                $person = $people->get($staffId);

                return [
                    'name'   => $person->name ?? $staffId,
                    'dept'   => $person->department ?? '',
                    'office' => $person->office ?? '',
                    'kind'   => ($person && $person->role === 'employee') ? '社員' : 'スタッフ',
                    'count'  => $count,
                    'big'    => (int) ($bigCountByStaff[$staffId] ?? 0),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $totalAttendance = $countByStaff->sum();

        // 部署別の合計出勤数（イベプラ・セールス＋参考でクリエイティブ）。
        $byDept = collect(['イベプラ', 'セールス', 'クリエイティブ'])->map(fn ($dept) => [
            'dept'  => $dept,
            'count' => $members->where('dept', $dept)->sum('count'),
            'heads' => $members->where('dept', $dept)->count(),
        ]);

        return view('stats', [
            'span'            => $span,
            'spanOptions'     => $options,
            'selected'        => $selected,
            'selectedLabel'   => collect($options)->firstWhere('value', $selected)['label'] ?? '',
            'totalEvents'     => $totalEvents,
            'realEvents'      => $realEvents,
            'onlineEvents'    => $onlineEvents,
            'byOffice'        => $byOffice,
            'totalAttendance' => $totalAttendance,
            'byDept'          => $byDept,
            'members'         => $members,
        ]);
    }

    /**
     * 案件 → 拠点（事務所）名。各案件を1つの拠点だけに割り当てる。
     * ①対象拠点(base_locations)が入っていればその先頭 ②無ければ実施形態から
     *   （東北→東北／他拠点→「他拠点（未指定）」／東・ARENA→東京／それ以外→その他）。
     * ※ 現状は東京のみ運営で対象拠点は未入力。他拠点の細かい振り分けは今後の宿題。
     */
    private function officeOf(Project $p): string
    {
        $bl = is_array($p->base_locations) ? array_values(array_filter($p->base_locations)) : [];
        if (! empty($bl)) {
            return (string) $bl[0];
        }

        $f = (string) $p->format;

        return match (true) {
            str_contains($f, '東北')   => '東北',
            str_contains($f, '他拠点') => '他拠点（未指定）',
            str_contains($f, 'ARENA') => '東京',
            str_contains($f, '東')     => '東京',
            default                    => 'その他',
        };
    }

    /** オンライン案件かどうか（実施形態に「オンライン」を含む）。 */
    private function isOnline(?string $format): bool
    {
        return str_contains((string) $format, 'オンライン');
    }

    /** 日付 → 期間キー（span 別）。month=YYYY-MM／quarter=YYYY-Qn／year=YYYY。 */
    private function periodKey(Carbon $d, string $span): string
    {
        return match ($span) {
            'quarter' => $d->format('Y') . '-Q' . (int) ceil(((int) $d->format('n')) / 3),
            'year'    => $d->format('Y'),
            default   => $d->format('Y-m'),
        };
    }

    /** 期間キー → 表示ラベル。 */
    private function periodLabel(string $key, string $span): string
    {
        return match ($span) {
            'quarter' => substr($key, 0, 4) . '年 第' . substr($key, -1) . '四半期',
            'year'    => $key . '年',
            default   => substr($key, 0, 4) . '年' . (int) substr($key, 5, 2) . '月',
        };
    }

    /** 案件から期間の選択肢（value/label）を作る（案件のある期間だけ・昇順）。 */
    private function periodOptions(Collection $projects, string $span): array
    {
        return $projects
            ->map(fn (Project $p) => $this->periodKey($p->start_date, $span))
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $key) => ['value' => $key, 'label' => $this->periodLabel($key, $span)])
            ->all();
    }
}
