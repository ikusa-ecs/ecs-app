<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectShare;
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
        return view('stats', $this->aggregate($request));
    }

    /**
     * いまの集計を CSV 1枚にまとめてダウンロード（Excelの文字化け防止に UTF-8 BOM 付き）。
     * 並びは画面（ダッシュボード）と同じ：イベント数→規模別→他拠点依頼→(全拠点なら)拠点別→部署別→社員別→スタッフ別。
     * 社員別は画面と同じグループ分け（全拠点＝拠点ごと／拠点を選ぶと部署ごと）。画面と同じ期間・表示範囲で集計する。
     */
    public function exportCsv(Request $request)
    {
        $data = $this->aggregate($request);
        $scopeLabel = $data['scopeOffice'] !== '' ? $data['scopeOffice'] : '全拠点';

        // 社員別のグループ分け（画面と同じ）。全拠点＝拠点ごと／特定の拠点＝部署ごと。
        $emp = $data['members']->where('kind', '社員');
        if ($data['scopeOffice'] !== '') {
            $groupCol = '部署';
            $groupKey = 'dept';
            $order = ['イベプラ', 'セールス', 'クリエイティブ'];
        } else {
            $groupCol = '拠点';
            $groupKey = 'office';
            $order = self::OFFICE_ORDER;
        }
        $grouped = $emp->groupBy(fn ($m) => ($m[$groupKey] ?? '') !== '' ? $m[$groupKey] : '（' . $groupCol . '未設定）');
        $groupKeys = collect($order)->filter(fn ($k) => $grouped->has($k))
            ->merge($grouped->keys()->diff($order))->values();

        // ダッシュボードと同じ並びで行を積む。
        $rows = [];
        $rows[] = ['ECS集計ダッシュボード'];
        $rows[] = ['期間', $data['selectedLabel']];
        $rows[] = ['表示範囲', $scopeLabel];
        $rows[] = [];
        $rows[] = ['■ イベント数'];
        $rows[] = ['合計', $data['totalEvents']];
        $rows[] = ['リアル', $data['realEvents']];
        $rows[] = ['オンライン', $data['onlineEvents']];
        $rows[] = [];
        $rows[] = ['■ 規模別イベント数'];
        foreach ($data['byScale'] as $s) {
            $rows[] = [$s['scale'], $s['count']];
        }
        $rows[] = [];
        $rows[] = ['■ 他拠点依頼数'];
        foreach ($data['otherBase'] as $o) {
            $rows[] = [$o['label'], $o['count']];
        }
        $rows[] = [];
        if ($data['scopeOffice'] === '') {
            $rows[] = ['■ 拠点別イベント数'];
            $rows[] = ['拠点', '件数', 'うち大型'];
            foreach ($data['byOffice'] as $b) {
                $rows[] = [$b['office'], $b['count'], $b['big']];
            }
            $rows[] = [];
        }
        $rows[] = ['■ 部署別 出勤・ディレクター'];
        $rows[] = ['部署', '合計出勤', '合計D', '期間内出勤人数', '部署の社員数', '1人平均出勤', '1人平均D'];
        foreach ($data['byDept'] as $d) {
            $rows[] = [$d['dept'], $d['count'], $d['director'], $d['active'], $d['headcount'], $d['avgEvents'], $d['avgDirector']];
        }
        $rows[] = [];
        $rows[] = ['■ 社員別 イベント出勤・ディレクター内訳（' . $groupCol . 'ごと）'];
        foreach ($groupKeys as $gk) {
            $g = $grouped[$gk];
            $rows[] = ['【' . $gk . '】（' . $g->count() . '名）'];
            $rows[] = ['氏名', 'イベント出勤', 'うち大型', 'D＋SD合計', 'D', 'リアルD', '大型D', '大型SD', 'オンラインD'];
            foreach ($g as $m) {
                $rows[] = [$m['name'], $m['count'], $m['big'], $m['dTotal'], $m['d'], $m['realD'], $m['bigD'], $m['bigSD'], $m['onlineD']];
            }
            $rows[] = [];
        }
        $rows[] = ['■ スタッフ別 イベント出勤'];
        $rows[] = ['氏名', 'イベント出勤', 'うち大型'];
        foreach ($data['members']->where('kind', 'スタッフ')->sortByDesc('count') as $m) {
            $rows[] = [$m['name'], $m['count'], $m['big']];
        }

        // BOM＋各行を書き出す（$escape='' でPHP8.4のfputcsv非推奨警告を回避）。
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'ecs-stats_' . ($data['selected'] ?: 'all') . ($data['scopeOffice'] !== '' ? '_office' : '') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** 画面・CSVで共通の集計を作る。返り値はそのまま view() のデータになる。 */
    private function aggregate(Request $request): array
    {
        // 集計対象のイベント＝開催日があり、下書き・中止でない案件（完了＝過去実績は含める）。
        $projects = Project::whereNotNull('start_date')
            ->whereNotIn('status', ['下書き', 'キャンセル', '中止'])
            ->get(['id', 'start_date', 'format', 'base_locations', 'scale', 'office']);

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

        // 表示範囲＝全拠点（既定）／特定の拠点。拠点を選んだら、その拠点のイベントだけに絞る＝
        // 以降の集計（イベント数・規模・他拠点・出勤・部署・社員・スタッフ）すべてがその拠点の情報になる（baba 2026-07-27）。
        $scopeOffice = (string) $request->query('office', '');
        if (! in_array($scopeOffice, self::OFFICE_ORDER, true)) {
            $scopeOffice = '';   // 全拠点
        }
        // 拠点で絞る前の「その期間の全拠点ぶん」を控える（他拠点依頼数＝拠点をまたぐ共有の集計に使う）。
        $periodAll = $inPeriod;
        if ($scopeOffice !== '') {
            $inPeriod = $inPeriod->filter(fn (Project $p) => $this->officeOf($p) === $scopeOffice)->values();
        }

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

        // 規模別イベント数（大型／中型／小型）。0件でも常に表示。
        $byScale = collect(['大型', '中型', '小型'])->map(fn ($s) => [
            'scale' => $s,
            'count' => $inPeriod->filter(fn (Project $p) => (string) $p->scale === $s)->count(),
        ]);

        // 他拠点依頼数（拠点をまたぐ共有 project_shares から集計・全拠点運用 設計書19.2）。
        // 「拠点」視点＝拠点を選んでいればその拠点、全拠点のときは東京を基準にする。
        $home = $scopeOffice !== '' ? $scopeOffice : '東京';
        $ownerOf = $periodAll->pluck('office', 'id');   // 案件ID → 登録拠点
        $periodShares = $periodAll->isEmpty()
            ? collect()
            : ProjectShare::whereIn('project_id', $periodAll->pluck('id')->all())->get();
        $otherBase = [
            // 自拠点が登録した案件を、他拠点が巻き取って運営した数。
            ['label' => "{$home}→他拠点（依頼）", 'count' => $periodShares
                ->filter(fn ($s) => $s->kind === '巻き取り' && ($ownerOf[$s->project_id] ?? '') === $home)->count()],
            // 他拠点が登録した案件を、自拠点が巻き取った数。
            ['label' => "他拠点→{$home}（巻き取り）", 'count' => $periodShares
                ->filter(fn ($s) => $s->kind === '巻き取り' && $s->office === $home && ($ownerOf[$s->project_id] ?? '') !== $home)->count()],
            // ヘルプ（人だけ）。自拠点が出した／受けたぶん。
            ['label' => 'ヘルプ', 'count' => $periodShares
                ->filter(fn ($s) => $s->kind === 'ヘルプ' && ($s->office === $home || ($ownerOf[$s->project_id] ?? '') === $home))->count()],
        ];

        // ── 出勤数の集計 ──（この期間の案件に対するアサイン・キャンセル除く）
        $projectIds = $inPeriod->pluck('id');
        $assignments = $projectIds->isEmpty()
            ? collect()
            : Assignment::whereIn('project_id', $projectIds)
                ->where('status', '!=', 'キャンセル')
                ->get(['staff_id', 'project_id', 'role']);

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

        // ディレクター内訳＝役割D/SDの案件数（同案件は1回）＋実施形態・規模別。
        // 「社員・ディレクター集計」(/projects-agg)と同じ定義：リアル=formatに「リアル」／オンライン=「オンライン」／大型=scale「大型」。
        //  D計・SD計／リアルD（Dのうちリアル）／大型D（リアル&大型のD）／大型SD（リアル&大型のSD）／オンラインD（Dのうちオンライン）。
        $projMeta = $inPeriod->keyBy('id')->map(fn (Project $p) => [
            'real'   => str_contains((string) $p->format, 'リアル'),
            'online' => str_contains((string) $p->format, 'オンライン'),
            'big'    => ((string) $p->scale === '大型'),
        ]);
        $dirStats = [];   // staff_id => [d, sd, realD, bigD, bigSD, onlineD]
        $dirSeen = [];    // 案件×人×役割の重複（複数日）を1回に
        foreach ($assignments->whereIn('role', ['D', 'SD']) as $a) {
            $meta = $projMeta[$a->project_id] ?? null;
            if (! $meta) {
                continue;
            }
            $dkey = $a->project_id . '|' . $a->staff_id . '|' . $a->role;
            if (isset($dirSeen[$dkey])) {
                continue;
            }
            $dirSeen[$dkey] = true;
            $sid = $a->staff_id;
            $dirStats[$sid] ??= ['d' => 0, 'sd' => 0, 'realD' => 0, 'bigD' => 0, 'bigSD' => 0, 'onlineD' => 0];
            if ($a->role === 'D') {
                $dirStats[$sid]['d']++;
                if ($meta['real']) {
                    $dirStats[$sid]['realD']++;
                }
                if ($meta['online']) {
                    $dirStats[$sid]['onlineD']++;
                }
                if ($meta['real'] && $meta['big']) {
                    $dirStats[$sid]['bigD']++;
                }
            } else {
                $dirStats[$sid]['sd']++;
                if ($meta['real'] && $meta['big']) {
                    $dirStats[$sid]['bigSD']++;
                }
            }
        }

        // 社員は「登録している全員」を出す＝アサインの有無に関係なく表示（baba 2026-07-28）。
        // スタッフは人数が多いので、この期間に出勤した人だけ。氏名・部署・拠点をまとめて引く。
        $neededIds = Person::where('role', 'employee')->pluck('id')
            ->merge($countByStaff->keys())->unique()->values();
        $people = Person::whereIn('id', $neededIds->all())
            ->get(['id', 'name', 'role', 'department', 'office'])
            ->keyBy('id');

        // メンバー一覧（社員＝全員／スタッフ＝出勤ありのみ・拠点別のときは社員はその拠点だけ）。出勤の多い順。
        $members = $people
            ->map(function (Person $person) use ($countByStaff, $bigCountByStaff, $dirStats) {
                $id = $person->id;
                $ds = $dirStats[$id] ?? ['d' => 0, 'sd' => 0, 'realD' => 0, 'bigD' => 0, 'bigSD' => 0, 'onlineD' => 0];

                return [
                    'name'     => $person->name ?? $id,
                    'dept'     => $person->department ?? '',
                    'office'   => $person->office ?? '',
                    'kind'     => $person->role === 'employee' ? '社員' : 'スタッフ',
                    'count'    => (int) ($countByStaff[$id] ?? 0),
                    'big'      => (int) ($bigCountByStaff[$id] ?? 0),
                    'd'        => $ds['d'],
                    'sd'       => $ds['sd'],
                    'dTotal'   => $ds['d'] + $ds['sd'],   // D＋SD合計
                    'realD'    => $ds['realD'],
                    'bigD'     => $ds['bigD'],
                    'bigSD'    => $ds['bigSD'],
                    'onlineD'  => $ds['onlineD'],
                    'director' => $ds['d'],               // 部署別の平均で使う（D数）
                ];
            })
            ->filter(function (array $m) use ($scopeOffice) {
                if ($m['kind'] === '社員') {
                    // 全拠点＝全社員／拠点を選んだときは、その拠点の社員だけ（他拠点の社員は隠す）。
                    return $scopeOffice === '' || $m['office'] === $scopeOffice;
                }

                return $m['count'] > 0;   // スタッフは出勤がある人だけ
            })
            ->sortByDesc('count')
            ->values();

        $totalAttendance = $countByStaff->sum();

        // 部署ごとの社員数（＝平均の分母。所属が設定された社員だけ）。
        $deptDefs = ['イベプラ', 'セールス', 'クリエイティブ'];
        $headByDept = Person::where('role', 'employee')
            ->whereIn('department', $deptDefs)
            ->get(['id', 'department'])
            ->groupBy('department')
            ->map(fn (Collection $g) => $g->count());

        // 部署別＝合計出勤・合計ディレクター＋1人あたり平均（分母＝部署の社員数）。
        $byDept = collect($deptDefs)->map(function ($dept) use ($members, $headByDept) {
            $m = $members->where('dept', $dept);
            $head = (int) ($headByDept[$dept] ?? 0);
            $sumEvents = $m->sum('count');
            $sumDirector = $m->sum('director');

            return [
                'dept'        => $dept,
                'count'       => $sumEvents,       // 合計出勤（のべ日数）
                'director'    => $sumDirector,     // 合計ディレクター数
                'active'      => $m->where('count', '>', 0)->count(),   // 期間内に出勤した人数（0の社員は除く）
                'headcount'   => $head,            // 部署の社員数（平均の分母）
                'avgEvents'   => $head > 0 ? round($sumEvents / $head, 1) : 0,
                'avgDirector' => $head > 0 ? round($sumDirector / $head, 1) : 0,
            ];
        });

        return [
            'span'            => $span,
            'spanOptions'     => $options,
            'selected'        => $selected,
            'scopeOffice'     => $scopeOffice,
            'offices'         => self::OFFICE_ORDER,
            'selectedLabel'   => collect($options)->firstWhere('value', $selected)['label'] ?? '',
            'totalEvents'     => $totalEvents,
            'realEvents'      => $realEvents,
            'onlineEvents'    => $onlineEvents,
            'byOffice'        => $byOffice,
            'byScale'         => $byScale,
            'otherBase'       => $otherBase,
            'totalAttendance' => $totalAttendance,
            'byDept'          => $byDept,
            'members'         => $members,
        ];
    }

    /**
     * 案件 → 拠点（事務所）＝登録拠点（office列）。全拠点運用・設計書19.2。
     * これがイベント数の「全社合計＝登録拠点のみ」の基準。
     * 旧データ互換：office が空なら対象拠点(base_locations)→なければ東京扱い。
     */
    private function officeOf(Project $p): string
    {
        if (! empty($p->office)) {
            return (string) $p->office;
        }

        $bl = is_array($p->base_locations) ? array_values(array_filter($p->base_locations)) : [];
        if (! empty($bl)) {
            return (string) $bl[0];
        }

        return '東京';   // 未設定は東京扱い（現状は全件 office あり）
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
