<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentDifficulty;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 新人ページの中身＝新人ごとの進み具合と、FCに入れる案（2026-10-09 baba要望）。
 *
 * 【決めたこと（baba 2026-10-09）】
 *   ・案を出すのは **FCだけ**。Dは人がD決め画面で決める（ここは見るだけ・何も保存しない）。
 *   ・FCで経験してからDをしてほしい＝進み具合に「FC済・D済」を出して、D決めの参考にする。
 *   ・FCに入れる案件＝**運営人数に空きがある**案件（埋まった人数＝アサイン＋派遣）。
 *     1案件に入れる新人の数＝ふつう1人／運営人数が8名を超えると2人／大型で12名以上なら何人でも（capFor）。
 *   ・その月の目標（FC＝イベント数−D数）に届いていない新人から入れる。
 * 【人の選び方】その日すでに入っている人・出勤可能日が×／希望休の人は入れない。
 *   点の高い順＝まだFCでやっていないコンテンツ（必修・推奨ならもっと高い）／出勤可能日が〇／
 *   難易度が低い／イベプラ（シートの優先順位＝新人イベプラ→新人セールス）／目標までの残りが多い。
 */
final class RookieFcPlan
{
    /**
     * @return array{rookies: list<array>, picks: list<array>, unlinked:int}
     */
    public static function build(Carbon $month, ?string $office): array
    {
        $month = $month->copy()->startOfMonth();
        $today = Carbon::today();
        $rookies = Rookies::list($office);
        $ids = $rookies->pluck('id')->all();
        $map = RookieDifficulty::map();
        $contentNames = Content::pluck('content_name', 'id')->all();

        // 新人のアサイン（キャンセル以外）＋その案件。キャンセルになった案件は経験にも月の件数にも入れない（2026-10-21）。
        $rows = Assignment::whereIn('staff_id', $ids)->where('status', '!=', 'キャンセル')->onLiveProject()->get();
        $projects = Project::whereIn('id', $rows->pluck('project_id')->unique())->get()->keyBy('id');

        $mustRows = ContentDifficulty::whereNotNull('content_id')->where(fn ($q) => $q->where('must', true)->orWhere('recommend', true))->get();

        $out = [];
        $state = [];   // id => [remain, fcDone(set), busy(set of dates)]
        foreach ($rookies as $p) {
            $mine = $rows->where('staff_id', $p->id);
            $fcDone = [];
            $dDone = [];
            $busy = [];
            $monthEvents = 0;
            $monthD = 0;
            $monthCases = [];   // この月に入っている案件（2026-10-09 baba「新人一覧で案件一覧が出るように」）
            foreach ($mine as $a) {
                $date = Carbon::parse($a->date);
                $busy[$date->format('Y-m-d')] = true;
                $pr = $projects->get($a->project_id);
                if ($date->isSameMonth($month)) {
                    $monthCases[] = [
                        'date' => $date->format('Y-m-d'),
                        'projectId' => $a->project_id,
                        'name' => $pr ? ProjectContentName::of($pr, $contentNames, (string) $pr->project_name) : $a->project_id,
                        'client' => $pr ? (string) ($pr->client ?? '') : '',
                        'dayType' => $pr ? (string) ($pr->date_type ?? '本番') : '',
                        'role' => (string) ($a->role ?: '—'),
                        'status' => (string) $a->status,
                    ];
                    $monthEvents++;
                    if ($a->role === 'D') {
                        $monthD++;
                    }
                }
                if (! $pr || $date->gte($today)) {
                    continue;   // やったことに数えるのは開催済みだけ
                }
                foreach (is_array($pr->content_ids) ? $pr->content_ids : [] as $cid) {
                    if ($a->role === 'D') {
                        $dDone[$cid] = ($dDone[$cid] ?? 0) + 1;
                    } else {
                        $fcDone[$cid] = ($fcDone[$cid] ?? 0) + 1;
                    }
                }
            }

            // 手で直した経験（2026-10-09 baba「大型で受付だったから実は経験していない、みたいなことがある」）。
            // done＝やったことにする／none＝やっていないことにする／無し＝アサインから自動。
            $autoFc = $fcDone;
            $autoD = $dDone;
            $ov = is_array($p->rookie_overrides) ? $p->rookie_overrides : [];
            $fcDone = self::applyOverride($fcDone, $ov, 'fc');
            $dDone = self::applyOverride($dDone, $ov, 'd');
            // 手で直す表に出すコンテンツ＝必修・推奨＋アサインで自動で数えたもの＋手で直したもの。
            $expIds = array_values(array_unique(array_merge(
                $mustRows->pluck('content_id')->map(fn ($v) => (string) $v)->all(),
                array_map('strval', array_keys($autoFc)), array_map('strval', array_keys($autoD)),
                array_map('strval', array_keys($ov))
            )));
            $exp = [];
            foreach ($expIds as $cid) {
                $exp[] = [
                    'id' => $cid, 'name' => $contentNames[$cid] ?? $cid,
                    'fcAuto' => $autoFc[$cid] ?? 0, 'dAuto' => $autoD[$cid] ?? 0,
                    'fcOv' => (string) ($ov[$cid]['fc'] ?? ''), 'dOv' => (string) ($ov[$cid]['d'] ?? ''),
                ];
            }
            usort($exp, fn ($a, $b) => strcmp($a['name'], $b['name']));

            $no = Rookies::monthNo($p, $month);
            // 表はセールス版／イベプラ版を所属で選ぶ（2026-10-13）。D数を決めない月は実際のD数で引く。
            $target = Rookies::targetFor($no, Rookies::kindOf($p), $monthD);
            $fcThisMonth = $monthEvents - $monthD;

            // 必修・推奨の進み具合（Dでやったら「済」・FCだけなら「D準備OK」）。
            $progress = ['must' => self::progress($mustRows->where('must', true), $fcDone, $dDone, $contentNames),
                'recommend' => self::progress($mustRows->where('recommend', true), $fcDone, $dDone, $contentNames)];

            $out[] = [
                'id' => $p->id,
                'name' => $p->name,
                'dept' => (string) ($p->department ?? ''),
                'hire' => optional($p->hire_date)->format('Y-m-d'),
                'since' => optional($p->rookie_since)->format('Y-m-d'),
                'state' => (string) ($p->rookie_state ?? ''),
                // OJT担当とひとことメモ（2026-10-09 baba「新人とOJTを記載できるところもほしい」）。
                'ojt' => (string) ($p->rookie_ojt_id ?? ''),
                // セールスの新人はOJTが2人＝イベプラ側（ojt）とセールス側（ojtSales）（2026-10-19 baba）。
                'sales' => in_array(Rookies::SALES, $p->departmentList(), true),
                'ojtSales' => (string) ($p->rookie_ojt_sales_id ?? ''),
                // チームリーダー（2026-10-13 baba）。
                'leader' => (string) ($p->rookie_leader_id ?? ''),
                'note' => (string) ($p->rookie_note ?? ''),
                // 参加したいイベント（2026-10-17 baba）＝コンテンツIDの並び。
                'wishes' => array_values(array_map('strval', (array) ($p->rookie_wishes ?? []))),
                'monthNo' => $no,
                'target' => $target,
                'monthEvents' => $monthEvents,
                'monthD' => $monthD,
                'monthFc' => $fcThisMonth,
                'progress' => $progress,
                'exp' => $exp,
                // 手で直したぶんも入れた経験の回数（コンテンツID → 回数）。「📚 難易度表と経験」の表で使う（2026-10-17 baba）。
                'fcDone' => array_map('intval', $fcDone),
                'dDone' => array_map('intval', $dDone),
                'cases' => collect($monthCases)->sortBy('date')->values()->all(),
                // アサインチャットに貼る文は、FCの案がそろってから作る（下・copyText）。
            ];
            $state[$p->id] = [
                'person' => $p,
                'remain' => $target ? max(0, $target['fc'] - $fcThisMonth) : 0,
                'fcDone' => $fcDone,
                'busy' => $busy,
                'plan' => in_array('イベプラ', $p->departmentList(), true),
                'wishes' => array_flip(array_map('strval', (array) ($p->rookie_wishes ?? []))),
            ];
        }

        $picks = self::picks($month, $office, $state, $map, $contentNames);
        // 案のとおりに入れたら何件になるか（2026-10-09 baba）。数えるのはこの月の日だけ（月の外の同じイベントの日は別）。
        $ojtNames = Person::whereIn('id', array_filter(array_merge(array_column($out, 'ojt'), array_column($out, 'ojtSales'), array_column($out, 'leader'))))->pluck('name', 'id');
        // メンション用（本人・OJT・リーダー）。名前とチャットワークIDを1回でまとめて引く。
        $cwPeople = Person::whereIn('id', array_filter(array_merge(array_column($out, 'id'), array_column($out, 'ojt'), array_column($out, 'ojtSales'), array_column($out, 'leader'))))
            ->get(['id', 'name', 'chatwork_id'])->keyBy('id');
        foreach ($out as &$r) {
            $mine = array_filter($picks, fn ($x) => $x['rookieId'] === $r['id']);
            $r['pickInMonth'] = count(array_filter($mine, fn ($x) => Carbon::parse($x['date'])->isSameMonth($month)));
            $r['pickOutside'] = count($mine) - $r['pickInMonth'];
            $r['afterFc'] = $r['monthFc'] + $r['pickInMonth'];
            // Dで入っている案件（2026-10-13 baba「ディレクターの一覧（今アサインされてる一覧）を見えるようにしたい」）。
            $r['dCases'] = array_values(array_filter($r['cases'], fn ($c) => $c['role'] === 'D'));
            $r['myPicks'] = array_values($mine);
            // OJT・本人に確認してもらう文（2026-10-13 baba「チャットワークでOJTと本人に確認したい・すぐコピペ」）。
            $r['copyText'] = self::copyText($r, (string) ($ojtNames[$r['ojt']] ?? ''), $month, (string) ($ojtNames[$r['leader']] ?? ''), (string) ($ojtNames[$r['ojtSales']] ?? ''));
            // 先頭にメンション（2026-10-19 baba「チャトワのIDも出してそのままメンションできるように」）。
            // 宛先＝本人＋OJT1人（同日 baba）。リーダーには付けない。セールスの新人はセールス側のOJTだけ（イベプラ側には付けない）。
            // 名簿にチャットワークIDがある人だけ。無い人は付けない（名簿で登録すれば付く）。
            $mentionIds = [$r['id'], $r['sales'] ? $r['ojtSales'] : $r['ojt']];
            $to = self::mentionLine($mentionIds, $cwPeople);
            if ($to !== '') {
                $r['copyText'] = $to."\n".$r['copyText'];
            }
            // IDが無くてメンションが付かない人（画面に「名簿で登録を」と出す）。
            $r['cwMissing'] = collect($mentionIds)->filter()->unique()
                ->map(fn ($id) => $cwPeople->get($id))->filter()
                ->filter(fn ($p) => trim((string) $p->chatwork_id) === '')
                ->pluck('name')->values()->all();
        }
        unset($r);

        return ['rookies' => $out, 'picks' => $picks,
            // 案に無い案件にも手で入れられるように（2026-10-14 baba「案の人や案件を変えられない」）。
            'openCases' => self::openCases($month, $office, $contentNames),
            // 新人ごとの、もう入っている日（案の新人を替えたとき・手で足すときに「その日は入っています」を出す）。
            'busy' => collect($state)->map(fn ($s) => array_keys($s['busy']))->all(),
            'unlinked' => ContentDifficulty::whereNull('content_id')->count()];
    }

    /**
     * 手で新人を足せる案件＝今日から月末・空きがある・ARENA場所貸しでない（案と同じ範囲）。
     * 空き＝運営人数 −（入っている人＋派遣）。案を入れる前の数。
     *
     * @return list<array{projectId: string, date: string, name: string, client: string, dayType: string, room: int}>
     */
    public static function openCases(Carbon $month, ?string $office, array $names): array
    {
        $from = Carbon::today()->max($month)->format('Y-m-d');
        $to = $month->copy()->endOfMonth()->format('Y-m-d').' 23:59:59';
        $cases = OfficeScope::hideTakenOver(OfficeScope::applyToProjects(Project::query(), $office), $office, true)
            ->notCancelled()->needsAssign()->whereNotNull('start_date')->whereNotIn('status', ['完了', '下書き'])
            ->whereBetween('start_date', [$from, $to])->orderBy('start_date')->orderBy('start_time')->get()
            ->reject(fn ($c) => self::isArenaRental($c, $names))->values();
        if ($cases->isEmpty()) {
            return [];
        }
        $assigned = Assignment::whereIn('project_id', $cases->pluck('id'))->where('status', '!=', 'キャンセル')->get()->groupBy('project_id');
        $dispatch = DispatchRows::liveCountsFor($cases->pluck('id'));

        $out = [];
        foreach ($cases as $c) {
            $room = RecruitStatus::need($c->required_count)
                - (($assigned->get($c->id) ?? collect())->pluck('staff_id')->unique()->count() + ($dispatch[$c->id] ?? 0));
            if ($room > 0) {
                $out[] = [
                    'projectId' => $c->id,
                    'date' => $c->start_date->format('Y-m-d'),
                    'name' => ProjectContentName::of($c, $names, (string) $c->project_name),
                    'client' => (string) ($c->client ?? ''),
                    'dayType' => (string) ($c->date_type ?? '本番'),
                    'room' => $room,
                ];
            }
        }

        return $out;
    }

    /**
     * 新人の月のアサインを、チャットワークに貼ってOJT・本人に確認してもらう文にする
     * （2026-10-09 baba「コピペしてOJTに見てもらいたい」→ 10-13 D／FC／これから入れる案に分けた）。
     * ⚠ 表や罫線は使わない（チャットワークでそのまま読めるように）。
     * 例：【新人】大霜 知弘（OJT：◯◯）
     *     11月のアサイン確認
     *     目標：イベント5件（うちD3件）／いま：D 1件・FC 2件
     *     ■ D（ディレクター）
     *     11/8(土) 戦国運動会／株式会社◯◯（仮）
     *     ■ FC・そのほか
     *     11/12(水) 水合戦／◯◯　FC（仮）
     *     ■ これからFCで入れる案（まだ入っていません）
     *     11/20(金) 謎パ／◯◯［必修・初めて］
     *     この内容で問題ないか確認をお願いします。
     */
    /**
     * チャットワークのメンション行。例：`[To:1234567]山田 太郎さん[To:7654321]鈴木 花子さん`
     * 同じ人（OJTとリーダーが同じ等）は1回だけ。IDが無い人は飛ばす。だれも付かなければ空文字。
     * 書き方は App\Support\ChatworkMentions::head と同じ。
     */
    private static function mentionLine(array $ids, $people): string
    {
        $out = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            $p = $people->get($id);
            $cw = trim((string) ($p->chatwork_id ?? ''));
            if ($p && $cw !== '') {
                $out[] = '[To:'.$cw.']'.$p->name.'さん';
            }
        }

        return implode('', $out);
    }

    private static function copyText(array $r, string $ojt, Carbon $month, string $leader = '', string $ojtSales = ''): string
    {
        $line = function (array $c, string $tail) {
            $d = Carbon::parse($c['date']);

            return $d->format('n/j').'('.self::WEEK[$d->dayOfWeek].') '.$c['name']
                .($c['client'] !== '' ? '／'.$c['client'] : '')
                .($c['dayType'] !== '' && $c['dayType'] !== '本番' ? '［'.$c['dayType'].'］' : '')
                .$tail;
        };

        // セールスの新人はOJTが2人＝「OJT：A（イベプラ）・B（セールス）」（2026-10-19 baba）。
        $ojts = ($r['sales'] ?? false)
            ? array_filter([$ojt !== '' ? $ojt.'（イベプラ）' : '', $ojtSales !== '' ? $ojtSales.'（セールス）' : ''])
            : array_filter([$ojt]);
        $who = array_filter([$ojts ? 'OJT：'.implode('・', $ojts) : '', $leader !== '' ? 'リーダー：'.$leader : '']);
        // 名前・OJT・リーダーのあとで改行（2026-10-19 baba「1行でわかりにくい」）。
        $lines = ['【新人】'.$r['name'].($who ? '（'.implode('／', $who).'）' : ''), $month->format('n月').'のアサイン確認'];
        if ($r['target']) {
            $t = $r['target'];
            $lines[] = '目標：イベント'.$t['events'].'件'.($t['dFree'] ? '（DとFC合わせて）' : '（うちD'.$t['d'].'件）')
                .'／いま：D '.$r['monthD'].'件・FC '.$r['monthFc'].'件';
        }

        $lines[] = '■ D（ディレクター）';
        foreach ($r['dCases'] as $c) {
            $lines[] = $line($c, '（'.$c['status'].'）');
        }
        if (! $r['dCases']) {
            $lines[] = '（なし）';
        }

        $others = array_values(array_filter($r['cases'], fn ($c) => $c['role'] !== 'D'));
        $lines[] = '■ FC・そのほか';
        foreach ($others as $c) {
            $lines[] = $line($c, '　'.$c['role'].'（'.$c['status'].'）');
        }
        if (! $others) {
            $lines[] = '（なし）';
        }

        if ($r['myPicks']) {
            $lines[] = '■ これからFCで入れる案（まだ入っていません）';
            foreach ($r['myPicks'] as $p) {
                $tags = array_filter([$p['mark'], $p['first'] ? '初めて' : '', $p['preStay'] ? '前泊' : '']);
                $lines[] = $line($p, $tags ? '［'.implode('・', $tags).'］' : '');
            }
        }
        $lines[] = 'この内容で問題ないか確認をお願いします。';

        return implode("\n", $lines);
    }

    private const WEEK = ['日', '月', '火', '水', '木', '金', '土'];

    /** 手で直した印を当てる（done＝やった／none＝やっていない）。 */
    private static function applyOverride(array $done, array $ov, string $key): array
    {
        foreach ($ov as $cid => $o) {
            $v = is_array($o) ? ($o[$key] ?? '') : '';
            if ($v === 'done') {
                $done[$cid] = max(1, $done[$cid] ?? 0);
            } elseif ($v === 'none') {
                unset($done[$cid]);
            }
        }

        return $done;
    }

    /** 必修・推奨のどこまで済んだか。◻︎▲★（同じ分類から1つ）は分類ごとに1つと数える。 */
    private static function progress(Collection $rows, array $fcDone, array $dDone, array $names): array
    {
        $units = [];
        foreach ($rows as $r) {
            $key = $r->one_of ? 'g:'.$r->kind.':'.$r->category : 'c:'.$r->content_id;
            $units[$key]['label'] = $r->one_of ? $r->category.'（どれか1つ）' : ($names[$r->content_id] ?? $r->sheet_name);
            $units[$key]['ids'][] = $r->content_id;
        }
        $done = 0;
        $readyD = [];
        $left = [];
        foreach ($units as $u) {
            $d = array_filter($u['ids'], fn ($id) => isset($dDone[$id]));
            if ($d) {
                $done++;

                continue;
            }
            $f = array_filter($u['ids'], fn ($id) => isset($fcDone[$id]));
            if ($f) {
                $readyD[] = $u['label'];
            } else {
                $left[] = $u['label'];
            }
        }

        return ['total' => count($units), 'done' => $done, 'readyD' => $readyD, 'left' => $left];
    }

    /**
     * FCに入れる案。
     *
     * 本番・リハ日・前日設営・予備日がある案件は、**同じ新人を、出られる日は全部**に入れる
     * （2026-10-09 baba「参加できそうなら全部参加にしてほしい」）。まとまり＝parent_project_id ?: id（ProjectSeries と同じ）。
     *   ・まず本番（無ければいちばん早い日）で新人を選び、ほかの日にも同じ人を入れる。
     *   ・ほかの日に入れないのは、その日もう入っている・出勤可能日が×／希望休・空きが無い・新人の上限のときだけ。
     *   ・ほかの日は、その月の目標を超えても入れる（同じイベントの流れを経験してもらうため）。
     *   ・ほかの日が月の外・今日より前なら入れない（ここに出すのは今日から月末まで＋同じイベントの先の日）。
     */
    private static function picks(Carbon $month, ?string $office, array $state, array $map, array $names): array
    {
        if ($state === []) {
            return [];
        }
        $today = Carbon::today()->format('Y-m-d');
        $from = Carbon::today()->max($month)->format('Y-m-d');
        $to = $month->copy()->endOfMonth()->format('Y-m-d').' 23:59:59';

        $base = fn () => OfficeScope::hideTakenOver(OfficeScope::applyToProjects(Project::query(), $office), $office, true)
            ->notCancelled()->needsAssign()->whereNotNull('start_date')->whereNotIn('status', ['完了', '下書き']);
        $cases = $base()->whereBetween('start_date', [$from, $to])->orderBy('start_date')->orderBy('start_time')->get()
            ->reject(fn ($c) => self::isArenaRental($c, $names))->values();
        if ($cases->isEmpty()) {
            return [];
        }

        // 同じイベントのほかの日（月の外でも、今日より先なら入れる）。
        $roots = $cases->map(fn ($c) => ProjectSeries::rootId($c))->unique()->values()->all();
        $related = $base()->where('start_date', '>=', $today)
            ->where(fn ($q) => $q->whereIn('id', $roots)->orWhereIn('parent_project_id', $roots))
            ->get()->reject(fn ($c) => self::isArenaRental($c, $names));
        $all = $cases->concat($related)->unique('id')->keyBy('id');
        $groups = $all->groupBy(fn ($c) => ProjectSeries::rootId($c))
            ->map(fn ($g) => $g->sortBy(fn ($c) => $c->start_date->format('Y-m-d').(string) $c->start_time)->values());

        $assigned = Assignment::whereIn('project_id', $all->keys())->where('status', '!=', 'キャンセル')->get()->groupBy('project_id');
        $dispatch = DispatchRows::liveCountsFor($all->keys());
        $lastDay = $all->max(fn ($c) => $c->start_date->format('Y-m-d'));
        $avail = ShiftPreference::whereIn('staff_id', array_keys($state))
            // 前泊の案件は前日も見るので、1日前から読む。
            ->whereBetween('date', [Carbon::parse($from)->subDay()->format('Y-m-d'), $lastDay.' 23:59:59'])->get()
            ->mapWithKeys(fn ($s) => [$s->staff_id.'|'.Carbon::parse($s->date)->format('Y-m-d') => $s->availability]);
        $mustIds = ContentDifficulty::whereNotNull('content_id')
            ->where(fn ($q) => $q->where('must', true)->orWhere('recommend', true))->pluck('content_id')->flip()->all();
        // 必修・推奨の札（2026-10-09 baba「必須とかもわかるようにしてほしい」）。
        $mustOnly = ContentDifficulty::whereNotNull('content_id')->where('must', true)->pluck('content_id')->map(fn ($v) => (string) $v)->flip()->all();

        // 案件ごとの空き（選ぶたびに減らす）。
        $room = [];
        $cap = [];
        foreach ($all as $c) {
            $rows = $assigned->get($c->id, collect());
            $need = RecruitStatus::need($c->required_count);
            $room[$c->id] = $need - ($rows->pluck('staff_id')->unique()->count() + ($dispatch[$c->id] ?? 0));
            $cap[$c->id] = self::capFor($c, $need) - $rows->filter(fn ($a) => isset($state[$a->staff_id]))->pluck('staff_id')->unique()->count();
        }
        $canGo = function (string $id, string $day, $c) use (&$state, &$room, &$cap, $avail) {
            $prev = self::preStayDay($c);

            return $room[$c->id] > 0 && $cap[$c->id] > 0 && ! isset($state[$id]['busy'][$day])
                && ! in_array($avail[$id.'|'.$day] ?? '', ['NG', '希望休'], true)
                && ($prev === null || self::freeDay($state[$id], $avail, $id, $prev));
        };

        $out = [];
        $put = function ($c, string $id, array $why, array $others) use (&$out, &$state, &$room, &$cap, $names, $map, $mustIds, $mustOnly) {
            $day = $c->start_date->format('Y-m-d');
            $cidsHere = array_map('strval', (array) $c->content_ids);
            $mark = array_filter($cidsHere, fn ($x) => isset($mustOnly[$x])) ? '必修'
                : (array_filter($cidsHere, fn ($x) => isset($mustIds[$x])) ? '推奨' : '');
            $first = $cidsHere && array_filter($cidsHere, fn ($x) => ! isset($state[$id]['fcDone'][$x]));
            $state[$id]['remain']--;
            $state[$id]['busy'][$day] = true;
            foreach ((array) $c->content_ids as $x) {
                $state[$id]['fcDone'][(string) $x] = ($state[$id]['fcDone'][(string) $x] ?? 0) + 1;
            }
            $out[] = [
                'date' => $day,
                'projectId' => $c->id,
                'name' => ProjectContentName::of($c, $names, (string) $c->project_name),
                'client' => (string) ($c->client ?? ''),
                'dayType' => (string) ($c->date_type ?? '本番'),
                'mark' => $mark,          // 必修／推奨／空
                'first' => (bool) $first,   // この新人がFCで初めてのコンテンツか
                'preStay' => self::preStayDay($c) !== null,
                'difficulty' => RookieDifficulty::ofProject($c, $map),
                'room' => $room[$c->id],
                'rookie' => $state[$id]['person']->name,
                'rookieId' => $id,
                'why' => implode('・', $why),
                'others' => $others,
            ];
            $room[$c->id]--;
            $cap[$c->id]--;
        };

        $handled = [];
        foreach ($cases as $c) {
            $root = ProjectSeries::rootId($c);
            if (isset($handled[$root])) {
                continue;
            }
            $handled[$root] = true;
            $group = $groups->get($root, collect([$c]));
            // 選ぶ日＝本番（無ければいちばん早い日）。ただし今日から月末のうちの日。
            // ⚠ 本番が満員なら、空きのあるほかの日（リハ等）から選ぶ。
            $inMonth = $group->filter(fn ($g) => $cases->contains('id', $g->id) && $room[$g->id] > 0 && $cap[$g->id] > 0);
            $lead = $inMonth->first(fn ($g) => ($g->date_type ?? '本番') === '本番') ?? $inMonth->first();
            if ($lead === null) {
                continue;
            }
            $others = $group->reject(fn ($g) => $g->id === $lead->id);
            $leadDay = $lead->start_date->format('Y-m-d');
            $cids = array_map('strval', (array) $lead->content_ids);
            $diff = RookieDifficulty::ofProject($lead, $map);

            while ($room[$lead->id] > 0 && $cap[$lead->id] > 0) {
                $pick = self::pickOne($state, $leadDay, $avail, $cids, $mustIds, $diff, self::preStayDay($lead),
                    ProjectScale::of($lead->scale) === '大型');
                if ($pick === null) {
                    break;
                }
                [$best, $cands] = $pick;
                $id = $best['id'];
                $sameDays = $others->filter(fn ($g) => $canGo($id, $g->start_date->format('Y-m-d'), $g));
                $why = $best['why'];
                if ($sameDays->isNotEmpty()) {
                    $why[] = '同じイベントの'.$sameDays->map(fn ($g) => $g->start_date->format('n/j').'（'.($g->date_type ?? '本番').'）')->implode('・').'にも入る';
                }
                $put($lead, $id, $why, array_map(fn ($o) => $state[$o['id']]['person']->name, array_slice($cands, 1, 2)));
                foreach ($sameDays as $g) {
                    $put($g, $id, ['同じイベントの'.$lead->start_date->format('n/j').'（'.($lead->date_type ?? '本番').'）と同じ人'], []);
                }
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['projectId'], $b['projectId']));

        return $out;
    }

    /**
     * ARENA場所貸しの案件か（2026-10-09 baba「新人のアサインからARENA場所貸しは外してほしい」）。
     * 実施形態が「ARENA場所貸し」、またはコンテンツ名・案件名が「ARENA場所貸し」「ARENA貸し出し」のもの。
     */
    public static function isArenaRental(Project $c, array $names = []): bool
    {
        if (str_contains((string) ($c->format ?? ''), 'ARENA場所貸し')) {
            return true;
        }
        foreach (array_merge(ProjectContentName::names($c, $names), [(string) $c->project_name]) as $n) {
            if (str_contains($n, 'ARENA場所貸し') || str_contains($n, 'ARENA貸し出し')) {
                return true;
            }
        }

        return false;
    }

    /** 前泊がある案件なら、その前日（Y-m-d）。無ければ null。判定の正本＝Lodging::hasPreStay。 */
    public static function preStayDay(Project $c): ?string
    {
        return Lodging::hasPreStay($c->lodging) && $c->start_date
            ? $c->start_date->copy()->subDay()->format('Y-m-d') : null;
    }

    /** その日、ほかの案件に入っておらず、出勤可能日が×／希望休でもないか。 */
    private static function freeDay(array $s, $avail, string $id, string $day): bool
    {
        return ! isset($s['busy'][$day]) && ! in_array($avail[$id.'|'.$day] ?? '', ['NG', '希望休'], true);
    }

    /**
     * 1案件に新人を何人まで入れてよいか（2026-10-09 baba）。
     *   ・ふつうは1人 ・運営人数が8名を超える案件は2人 ・大型で運営人数12名以上は何人でも（空きの数まで）
     */
    public static function capFor(Project $c, int $need): int
    {
        if (ProjectScale::of($c->scale) === '大型' && $need >= 12) {
            return PHP_INT_MAX;
        }

        return $need > 8 ? 2 : 1;
    }

    /** @return array{0: array, 1: list<array>}|null いちばん点の高い新人と、候補の一覧 */
    private static function pickOne(array $state, string $day, $avail, array $cids, array $mustIds, ?int $diff, ?string $prevDay = null, bool $big = false): ?array
    {
        $cands = [];
        foreach ($state as $id => $s) {
            if ($s['remain'] <= 0 || isset($s['busy'][$day])) {
                continue;
            }
            // 前泊の案件＝前日も空いていないと入れない（2026-10-09 baba「日曜の案件で土曜が×ならアサインできない」）。
            if ($prevDay !== null && ! self::freeDay($s, $avail, $id, $prevDay)) {
                continue;
            }
            $av = $avail[$id.'|'.$day] ?? '';
            if (in_array($av, ['NG', '希望休'], true)) {
                continue;
            }
            $score = 0;
            $why = [];
            $newC = array_filter($cids, fn ($x) => ! isset($s['fcDone'][$x]));
            if ($cids && $newC) {
                $isMust = (bool) array_filter($newC, fn ($x) => isset($mustIds[$x]));
                $score += $isMust ? 3 : 2;
                $why[] = $isMust ? 'まだFCでやっていない必修・推奨' : 'まだFCでやっていないコンテンツ';
            } elseif ($big) {
                // 大型はいろいろ学べるので、経験があっても後回しにしない（2026-10-14 baba）。
                $score += 2;
                $why[] = '大型（経験があっても学べる）';
            }
            // 本人が参加したいイベント（2026-10-17 baba「希望を優先に」）。
            // 強さは「まだFCでやっていないコンテンツ」と同じ＋2（baba「同じくらいでOK」）。強くしすぎると必修が後回しになる。
            if ($cids && array_filter($cids, fn ($x) => isset($s['wishes'][$x]))) {
                $score += 2;
                $why[] = '本人の希望';
            }
            if (in_array($av, ['稼働可', '希望'], true)) {
                $score += 2;
                $why[] = '出勤可能日〇';
            } else {
                $why[] = '出勤可能日は未入力';
            }
            if ($prevDay !== null) {
                $why[] = '前泊あり（前日も空いている）';
            }
            if ($diff !== null) {
                $score += (4 - $diff) * 0.5;
            }
            if ($s['plan']) {
                $score += 0.5;
            }
            $score += min($s['remain'], 5) * 0.1;
            $cands[] = ['id' => $id, 'score' => $score, 'why' => $why];
        }
        if (! $cands) {
            return null;
        }
        usort($cands, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [$cands[0], $cands];
    }
}
