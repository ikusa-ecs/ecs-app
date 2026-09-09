<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Support\Departments;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Support\EventCount;
use App\Support\HireDate;
use App\Support\ProjectScale;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 集計ダッシュボード（/stats）。
 *
 * ねらい：これまで無かった「経営・運営の全体集計」を1画面にまとめる（baba 2026-07-24）。
 *  ・イベント数（合計／規模別＝小型・中型・大型／拠点ごと）＋**前年同期との比較（昨対比）**
 *    ⚠ 2026-09-09 上長要望で、主役を「リアル／オンライン」から「小型・中型・大型」に入れ替えた。
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
    private const OFFICE_ORDER = ['東京', '名古屋', '大阪', '福岡', '札幌', '東北'];

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
        if ($data['scopeOffice'] !== '' || $data['scopeDept'] !== '') {
            $groupCol = '部署';
            $groupKey = 'deptGroup';
            $order = Departments::GROUPS;   // イベプラ／セールス／クリエイティブ／その他
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
        // 画面と同じ条件で出していることが、CSVだけ見ても分かるようにしておく。
        $rows[] = ['所属', $data['scopeDept'] !== '' ? $data['scopeDept'] : 'すべて'];
        $rows[] = ['並び順', $data['sort'] === 'count' ? '出勤数が多い順' : '社歴順（入社が古い人が上）'];
        if ($data['scopeDept'] !== '') {
            $rows[] = ['※ イベント数は案件ごとの集計なので、所属では変わりません'];
        }
        $rows[] = [];
        $rows[] = ['■ イベント数'];
        $rows[] = ['合計', $data['totalEvents']];
        // 数えなかった案件（体験会・EXPO など）＝画面の注記と同じ情報をCSVにも残す（先-2）
        if ($data['excludedCount'] > 0) {
            $rows[] = ['数えていない案件', $data['excludedCount'],
                $data['excludedReasons']->map(fn ($n, $reason) => "{$reason} {$n}件")->implode(' / ')];
        }
        $rows[] = [];
        // 規模別＋昨対比（2026-09-09 上長要望）。⚠ 前年のデータが無い期間は「-」にする（0件と区別する）。
        $ly = $data['lastYear'];
        $yoyCell = fn (array $y) => $y['has'] ? $y['prev'] : '-';
        $diffCell = fn (array $y) => $y['has']
            ? (($y['diff'] > 0 ? '+' : '') . $y['diff'] . ($y['pct'] === null ? '' : '（' . ($y['pct'] > 0 ? '+' : '') . $y['pct'] . '%）'))
            : 'データなし';
        $rows[] = ['■ 規模別イベント数（昨対比つき）'];
        $rows[] = ['', '今期', '前年同期' . ($ly['label'] !== '' ? '（' . $ly['label'] . '）' : ''), '増減'];
        $rows[] = ['合計', $data['totalEvents'], $yoyCell($data['yoyTotal']), $diffCell($data['yoyTotal'])];
        foreach ($data['byScale'] as $s) {
            $rows[] = [$s['scale'], $s['count'], $yoyCell($s['yoy']), $diffCell($s['yoy'])];
        }
        if ($data['scaleUnsetCount'] > 0) {
            $rows[] = ['※ うち案件規模が未入力', $data['scaleUnsetCount'],
                '', '「' . $data['scaleUnsetGoesTo'] . '」に数えています'];
        }
        $rows[] = ['のべ出勤数', $data['totalAttendance'],
            $yoyCell($data['yoyAttendance']), $diffCell($data['yoyAttendance'])];
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
        // スタッフに所属は無いので、所属で絞っているときは出さない（0名と誤解されるため・画面と同じ）。
        if ($data['scopeDept'] === '') {
            $rows[] = ['■ スタッフ別 イベント出勤'];
            $rows[] = ['氏名', 'イベント出勤', 'うち大型'];
            foreach ($data['members']->where('kind', 'スタッフ') as $m) {
                $rows[] = [$m['name'], $m['count'], $m['big']];
            }
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

        $filename = 'ecs-stats_' . ($data['selected'] ?: 'all')
            . ($data['scopeOffice'] !== '' ? '_office' : '')
            . ($data['deptCode'] !== '' ? '_' . $data['deptCode'] : '') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** 画面・CSVで共通の集計を作る。返り値はそのまま view() のデータになる。 */
    private function aggregate(Request $request): array
    {
        // 集計対象のイベント＝開催日があり、下書き・中止でない案件（完了＝過去実績は含める）。
        $allProjects = Project::whereNotNull('start_date')
            ->whereNotIn('status', ['下書き', 'キャンセル', '中止'])
            ->get(['id', 'start_date', 'format', 'base_locations', 'scale', 'office',
                'project_name', 'client', 'count_as_event']);

        // ⚠ さらに「イベント数として数える案件」だけに絞る（先-2・2026-08-20）。
        //   社内の数え方では体験会・EXPO を数えないため、全部数えると社内の言い方とズレる。
        //   数えるかどうかの正本は App\Support\EventCount の1か所（案件ごとに手動で上書きもできる）。
        $projects = $allProjects->filter(fn (Project $p) => EventCount::counts($p))->values();

        // 期間の選択肢は「数えない案件しか無い月」も選べるように、絞る前の一覧から作る。
        $spanKeys = [
            'month'   => $this->periodOptions($allProjects, 'month'),
            'quarter' => $this->periodOptions($allProjects, 'quarter'),
            'year'    => $this->periodOptions($allProjects, 'year'),
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

        // 所属（イベプラ／セールス／クリエイティブ／その他）で絞る（FB No.10・baba 2026-09-03）。
        // ⚠ 拠点と違い、絞れるのは「人」の集計だけ（部署別・社員別・のべ出勤数）。
        //   イベント数・規模別・他拠点依頼は案件を数えているので、所属という考えが無い＝変わらない。
        //   所属の一覧はここに書かず App\Support\Departments が正本（増えてもこの画面は直さなくてよい）。
        $deptOptions = Departments::groupOptions();   // [コード => 所属名]
        $deptCode = (string) $request->query('dept', '');
        if (! isset($deptOptions[$deptCode])) {
            $deptCode = '';   // すべての所属
        }
        $scopeDept = $deptCode !== '' ? $deptOptions[$deptCode] : '';

        // 並び順＝社歴順（既定）／出勤数の多い順（FB No.11・baba 2026-09-03「基本的に社歴順だと分かりやすい」）。
        $sort = (string) $request->query('sort', 'hire');
        if (! in_array($sort, ['hire', 'count'], true)) {
            $sort = 'hire';
        }
        // 拠点で絞る前の「その期間の全拠点ぶん」を控える（他拠点依頼数＝拠点をまたぐ共有の集計に使う）。
        $periodAll = $inPeriod;
        if ($scopeOffice !== '') {
            $inPeriod = $inPeriod->filter(fn (Project $p) => $this->officeOf($p) === $scopeOffice)->values();
        }

        // 「数えなかった案件」の件数＝画面の注記に出す（数字が合わない理由が分かるように）。
        $excluded = $allProjects
            ->filter(fn (Project $p) => $this->periodKey($p->start_date, $span) === $selected)
            ->when($scopeOffice !== '', fn ($c) => $c->filter(fn (Project $p) => $this->officeOf($p) === $scopeOffice))
            ->filter(fn (Project $p) => ! EventCount::counts($p));
        $excludedCount = $excluded->count();
        // 何で外れたかの内訳（例：['体験会' => 3, 'EXPO' => 1]）。
        $excludedReasons = $excluded
            ->groupBy(fn (Project $p) => $p->count_as_event === false ? '手動で「数えない」' : (EventCount::autoReason($p) ?? 'その他'))
            ->map(fn ($rows) => $rows->count())
            ->sortDesc();

        // ── イベント数の集計 ──
        $totalEvents = $inPeriod->count();

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

        // ── 規模別イベント数（小型／中型／大型）＝この画面の主役（2026-09-09 上長要望）──
        // ⚠ どの規模に数えるかの正本は App\Support\ProjectScale の1か所（空欄の扱いもそこで決める）。
        //   ここに「空なら小型」と書き写さない。
        $scaleCounts = $this->countByScale($inPeriod);
        // 規模が入っていない案件の数＝画面に必ず添える（小型が多いのか、入力漏れが多いのか分かるように）。
        $scaleUnsetCount = $inPeriod->filter(fn (Project $p) => ProjectScale::isUnset($p->scale))->count();

        // ── 昨対比（前年同期）──（2026-09-09 上長要望）
        // ⚠ ECSに去年のデータが入っていない期間では、0件と「データなし」を必ず区別する。
        //   区別しないと「去年より100%減」と出てしまい、数字を信じてもらえなくなる。
        $lastYear = $this->lastYearFigures($allProjects, $span, $selected, $scopeOffice, $scopeDept);

        $byScale = collect(ProjectScale::all())->map(fn ($s) => [
            'scale' => $s,
            'count' => $scaleCounts[$s],
            // 前年同期の同じ規模（データが無ければ has=false）。
            'yoy' => $this->yoy($scaleCounts[$s], $lastYear['byScale'][$s] ?? 0, $lastYear['hasData']),
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
            ->get(['id', 'name', 'role', 'department', 'office', 'hire_date'])
            ->keyBy('id');

        // メンバー一覧（社員＝全員／スタッフ＝出勤ありのみ・拠点別のときは社員はその拠点だけ）。
        // 並び順は下の sortMembers（社歴順が既定）。
        $members = $people
            ->map(function (Person $person) use ($countByStaff, $bigCountByStaff, $dirStats) {
                $id = $person->id;
                $ds = $dirStats[$id] ?? ['d' => 0, 'sd' => 0, 'realD' => 0, 'bigD' => 0, 'bigSD' => 0, 'onlineD' => 0];
                $hire = $person->hire_date?->format('Y-m-d') ?? '';
                // 社歴順で並べたとき「なぜこの順なのか」が名前の横で分かるようにする。
                $hireLabel = preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $hire, $hm) === 1 && $hire !== HireDate::INCOMPLETE
                    ? $hm[1] . '年' . (int) $hm[2] . '月入社'
                    : '入社日未入力';

                return [
                    'name'     => $person->name ?? $id,
                    'dept'     => $person->department ?? '',
                    // 色分け・絞り込み・集計の単位（イベプラ／セールス／クリエイティブ／その他）。正本＝Departments。
                    'deptGroup' => Departments::group($person->department),
                    // 社歴の並び替えに使う入社年月日（'Y-m-d'／未入力は空）と、画面に出す言い方。
                    'hire'      => $hire,
                    'hireLabel' => $hireLabel,
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
            ->filter(function (array $m) use ($scopeOffice, $scopeDept) {
                // 所属で絞っているときは社員だけを出す。
                // ⚠ スタッフ（アルバイト）に所属は無いので、絞ると全員消えてしまい「0名」と誤解される。
                //   画面・CSVでは、そのときスタッフ別の欄そのものを出さない。
                if ($scopeDept !== '') {
                    return $m['kind'] === '社員'
                        && $m['deptGroup'] === $scopeDept
                        && ($scopeOffice === '' || $m['office'] === $scopeOffice);
                }

                if ($m['kind'] === '社員') {
                    // 全拠点＝全社員／拠点を選んだときは、その拠点の社員だけ（他拠点の社員は隠す）。
                    return $scopeOffice === '' || $m['office'] === $scopeOffice;
                }

                return $m['count'] > 0;   // スタッフは出勤がある人だけ
            })
            ->values();
        $members = $this->sortMembers($members, $sort);

        // のべ出勤数。所属で絞っているときは、その所属の人ぶんだけを足す
        //（絞ったのに数字が全社のままだと、どこの数字か分からなくなるため）。
        $totalAttendance = $scopeDept !== '' ? $members->sum('count') : $countByStaff->sum();

        // 部署ごとの社員数（＝平均の分母。所属が設定された社員だけ）。
        // 集計の単位は4グループ（イベプラ／セールス／クリエイティブ／その他）。正本＝Departments。
        // 所属で絞っているときは、その所属のカードだけ出す（他は0名0件になり、絞ったのか0なのか分からないため）。
        $deptDefs = $scopeDept !== '' ? [$scopeDept] : Departments::GROUPS;
        $headByDept = Person::where('role', 'employee')
            ->get(['id', 'department'])
            // ⚠ 「経営管理」など、まとめ先が「その他」になる所属の人も数に入れる（Departments::group が正本）。
            //   ここで department をそのまま突き合わせていたころは、その人たちの出勤が
            //   どの部署にも入らず、静かに消えていた。
            ->groupBy(fn (Person $p) => Departments::group($p->department))
            ->map(fn (Collection $g) => $g->count());

        // 部署別＝合計出勤・合計ディレクター＋1人あたり平均（分母＝部署の社員数）。
        $byDept = collect($deptDefs)->map(function ($dept) use ($members, $headByDept) {
            $m = $members->where('deptGroup', $dept);
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

        // いまの条件ひとそろい（画面のリンクとCSVで同じものを使う）。
        $query = ['span' => $span, 'period' => $selected, 'office' => $scopeOffice,
            'dept' => $deptCode, 'sort' => $sort];

        return [
            'span'            => $span,
            'spanOptions'     => $options,
            'selected'        => $selected,
            'scopeOffice'     => $scopeOffice,
            'offices'         => self::OFFICE_ORDER,
            // 所属の絞り込み（FB No.10）
            'scopeDept'       => $scopeDept,     // 絞っている所属名（空＝すべて）
            'deptCode'        => $deptCode,      // URLに載せる短い名前（plan/sales/creative/other）
            'deptOptions'     => $deptOptions,   // [コード => 所属名]。正本＝Departments
            'deptGroups'      => Departments::GROUPS,
            // 並び順（FB No.11）
            'sort'            => $sort,
            // 画面のリンクで「いまの条件」をそのまま持ち回すための一式。
            'query'           => $query,
            // 画面に置くリンク（1つだけ条件を変えて、他は今のまま）。
            // ⚠ Bladeでリンクを組み立てると、条件が増えたとき付け忘れる場所が必ず出るので、ここで作って渡す。
            'links'           => [
                'span'   => collect(['month', 'quarter', 'year'])
                    ->mapWithKeys(fn ($s) => [$s => $this->statsLink($query, ['span' => $s, 'period' => ''])])->all(),
                'office' => collect([''])->merge(self::OFFICE_ORDER)
                    ->mapWithKeys(fn ($o) => [$o => $this->statsLink($query, ['office' => $o])])->all(),
                'dept'   => collect([''])->merge(array_keys($deptOptions))
                    ->mapWithKeys(fn ($d) => [$d => $this->statsLink($query, ['dept' => $d])])->all(),
                'sort'   => collect(['hire', 'count'])
                    ->mapWithKeys(fn ($s) => [$s => $this->statsLink($query, ['sort' => $s])])->all(),
                'csv'    => '/stats/export.csv?' . http_build_query($query),
            ],
            'selectedLabel'   => collect($options)->firstWhere('value', $selected)['label'] ?? '',
            'totalEvents'     => $totalEvents,
            // 数えなかった案件（体験会・EXPO など）＝画面の注記用
            'excludedCount'   => $excludedCount,
            'excludedReasons' => $excludedReasons,
            // どの案件を数えなかったか（多いときは先頭10件だけ。残りは件数で示す）
            'excludedList'    => $excluded->take(10)->map(fn (Project $p) => [
                'date' => optional($p->start_date)->format('n/j'),
                'name' => (string) $p->project_name,
                'why'  => EventCount::label($p),
            ])->values(),
            'byOffice'        => $byOffice,
            'byScale'         => $byScale,
            // 規模別の内訳（KPIの大きい数字に使う）と、規模が入っていない件数（注記に必ず出す）。
            'scaleCounts'     => $scaleCounts,
            'scaleUnsetCount' => $scaleUnsetCount,
            'scaleUnsetGoesTo' => ProjectScale::UNSET_GOES_TO,
            // 昨対比（前年同期）。hasData=false のときは画面に「前年のデータがありません」と出す。
            'lastYear'        => $lastYear,
            'yoyTotal'        => $this->yoy($totalEvents, $lastYear['total'], $lastYear['hasData']),
            'otherBase'       => $otherBase,
            'totalAttendance' => $totalAttendance,
            'yoyAttendance'   => $this->yoy($totalAttendance, $lastYear['attendance'], $lastYear['hasData']),
            'byDept'          => $byDept,
            'members'         => $members,
        ];
    }

    /**
     * 規模ごとの件数（小型／中型／大型）。0件でも必ず3つとも返す。
     * ⚠ どの規模に数えるかは App\Support\ProjectScale が正本。
     *
     * @return array<string, int>
     */
    private function countByScale(Collection $projects): array
    {
        $out = array_fill_keys(ProjectScale::all(), 0);
        foreach ($projects as $p) {
            $out[ProjectScale::of($p->scale)]++;
        }

        return $out;
    }

    /**
     * 前年同期（昨対比）の数字（2026-09-09 上長要望「昨対比とかだせるとめっちゃいい」）。
     *
     * 【考え方】いま見ている期間の**1年前の同じ期間**と比べる。
     *   月＝2026年9月 ⇄ 2025年9月／四半期＝2026-Q3 ⇄ 2025-Q3／年＝2026年 ⇄ 2025年。
     *
     * ⚠ **0件と「データが無い」を必ず区別する。** ECSは2026年から使い始めたので、
     *   前年に案件が1件も入っていない期間がある。そこを「0件」と扱うと
     *   **どの数字も「前年比 -100%」**になり、画面全体が信用されなくなる。
     *   ⇒ 前年の同じ期間に案件が1件も無い（数えない案件も含めて0件）ときは hasData=false にして、
     *      画面には「前年のデータがありません」と出す。
     * ⚠ 拠点・所属の絞り込みは、いま見ている条件と同じものを掛ける（条件が違う数字と比べないため）。
     *
     * @return array{key:string, label:string, hasData:bool, total:int, byScale:array<string,int>, attendance:int}
     */
    private function lastYearFigures(Collection $allProjects, string $span, string $selected,
        string $scopeOffice, string $scopeDept): array
    {
        $key = $this->lastYearKey($selected, $span);

        $prevAll = $allProjects
            ->filter(fn (Project $p) => $this->periodKey($p->start_date, $span) === $key)
            ->when($scopeOffice !== '', fn (Collection $c) => $c->filter(fn (Project $p) => $this->officeOf($p) === $scopeOffice));

        // ⚠ 「数えない案件（体験会・EXPO）」しか無かった期間も“データはある”とみなす
        //   （その期間は本当に0件だった、と言えるため）。
        $hasData = $key !== '' && $prevAll->isNotEmpty();

        $prev = $prevAll->filter(fn (Project $p) => EventCount::counts($p))->values();

        return [
            'key'        => $key,
            'label'      => $key !== '' ? $this->periodLabel($key, $span) : '',
            'hasData'    => $hasData,
            'total'      => $prev->count(),
            'byScale'    => $this->countByScale($prev),
            'attendance' => $hasData ? $this->attendanceOf($prev, $scopeDept) : 0,
        ];
    }

    /**
     * その案件たちの「のべ出勤数」（キャンセル以外のアサインの行数＝出勤日数）。
     * 所属で絞っているときは、その所属の社員ぶんだけを足す（画面のいまの条件に合わせる）。
     */
    private function attendanceOf(Collection $projects, string $scopeDept): int
    {
        if ($projects->isEmpty()) {
            return 0;
        }

        $rows = Assignment::whereIn('project_id', $projects->pluck('id')->all())
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id']);

        if ($scopeDept === '' || $rows->isEmpty()) {
            return $rows->count();
        }

        // 所属で絞っているとき＝その所属の社員のぶんだけ数える（スタッフに所属は無いので入らない）。
        $ids = Person::whereIn('id', $rows->pluck('staff_id')->unique()->all())
            ->where('role', 'employee')
            ->get(['id', 'department'])
            ->filter(fn (Person $p) => Departments::group($p->department) === $scopeDept)
            ->pluck('id')
            ->flip();

        return $rows->filter(fn ($a) => isset($ids[$a->staff_id]))->count();
    }

    /**
     * 期間キー → 1年前の同じ期間のキー（2026-09 → 2025-09／2026-Q3 → 2025-Q3／2026 → 2025）。
     * ⚠ 頭の4桁（年）から1を引くだけ。月・四半期の部分はそのまま持ち越す。
     */
    private function lastYearKey(string $key, string $span): string
    {
        if (preg_match('/^(\d{4})(.*)$/', $key, $m) !== 1) {
            return '';
        }

        return ((int) $m[1] - 1) . $m[2];
    }

    /**
     * 昨対比の1項目分（前年の数・増減・増減率）。画面はこれをそのまま出すだけにする。
     *
     * ⚠ pct（増減率）は前年が0件のときは出せない（0で割れない）＝null にして、
     *   画面では「前年0件」と出す。0件から1件を「+100%」と書くと意味が違ってしまう。
     *
     * @return array{has:bool, prev?:int, diff?:int, pct?:?int}
     */
    private function yoy(int $now, int $prev, bool $hasData): array
    {
        if (! $hasData) {
            return ['has' => false];
        }

        return [
            'has'  => true,
            'prev' => $prev,
            'diff' => $now - $prev,
            'pct'  => $prev > 0 ? (int) round(($now - $prev) / $prev * 100) : null,
        ];
    }

    /** いまの条件のうち1つだけ差し替えた /stats のリンクを作る（他の条件は今のまま持ち回す）。 */
    private function statsLink(array $query, array $change): string
    {
        return '/stats?' . http_build_query(array_merge($query, $change));
    }

    /**
     * メンバーの並び順（画面もCSVもここ1か所で決める）。
     *
     * 'hire'（既定）＝社歴順。入社が古い人＝先輩が上（baba 2026-09-03「基本的に社歴順だと分かりやすい」）。
     *   ⚠ 入社年月日が空の人は**いちばん下**にまとめる。
     *     空を「いちばん古い」と扱うと、入れていない新人が先頭に並んで意味が逆になる。
     * 'count' ＝出勤数の多い順（これまでの並び）。
     *
     * 同じ値のときは「出勤の多い順→氏名順」で決める＝開き直しても並びが入れ替わらない。
     */
    private function sortMembers(Collection $members, string $sort): Collection
    {
        return $members->sortBy(function (array $m) use ($sort) {
            // 出勤数は「多い順」にしたいので、大きいほど小さい文字列になるように引き算しておく。
            $byCount = sprintf('%04d', 9999 - min(9999, (int) $m['count'])) . '|' . $m['name'];
            if ($sort === 'count') {
                return $byCount;
            }

            $hire = (string) $m['hire'];
            $filled = $hire !== '' && $hire !== HireDate::INCOMPLETE && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire) === 1;

            // '0'＋日付 … 入社日あり（古い順）／'1' … 入社日なし＝必ず後ろ。
            return ($filled ? '0' . $hire : '1') . '|' . $byCount;
        })->values();
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

    // ⚠ 「リアル／オンライン」のイベント数は 2026-09-09 に画面から外した（上長要望＝規模で見る）。
    //   社員別の「リアルD／オンラインD」は今までどおり残しているので、その判定は projMeta の中にある。

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
