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

        // 新人のアサイン（キャンセル以外）＋その案件。
        $rows = Assignment::whereIn('staff_id', $ids)->where('status', '!=', 'キャンセル')->get();
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
            foreach ($mine as $a) {
                $date = Carbon::parse($a->date);
                $busy[$date->format('Y-m-d')] = true;
                $pr = $projects->get($a->project_id);
                if ($date->isSameMonth($month)) {
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
            $target = Rookies::targetFor($no);
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
                'note' => (string) ($p->rookie_note ?? ''),
                'monthNo' => $no,
                'target' => $target,
                'monthEvents' => $monthEvents,
                'monthD' => $monthD,
                'monthFc' => $fcThisMonth,
                'progress' => $progress,
                'exp' => $exp,
            ];
            $state[$p->id] = [
                'person' => $p,
                'remain' => $target ? max(0, $target['fc'] - $fcThisMonth) : 0,
                'fcDone' => $fcDone,
                'busy' => $busy,
                'plan' => in_array('イベプラ', $p->departmentList(), true),
            ];
        }

        return ['rookies' => $out, 'picks' => self::picks($month, $office, $state, $map, $contentNames),
            'unlinked' => ContentDifficulty::whereNull('content_id')->count()];
    }

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
            ->whereBetween('date', [$from, $lastDay.' 23:59:59'])->get()
            ->mapWithKeys(fn ($s) => [$s->staff_id.'|'.Carbon::parse($s->date)->format('Y-m-d') => $s->availability]);
        $mustIds = ContentDifficulty::whereNotNull('content_id')
            ->where(fn ($q) => $q->where('must', true)->orWhere('recommend', true))->pluck('content_id')->flip()->all();

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
            return $room[$c->id] > 0 && $cap[$c->id] > 0 && ! isset($state[$id]['busy'][$day])
                && ! in_array($avail[$id.'|'.$day] ?? '', ['NG', '希望休'], true);
        };

        $out = [];
        $put = function ($c, string $id, array $why, array $others) use (&$out, &$state, &$room, &$cap, $names, $map) {
            $day = $c->start_date->format('Y-m-d');
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
                $pick = self::pickOne($state, $leadDay, $avail, $cids, $mustIds, $diff);
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
    private static function pickOne(array $state, string $day, $avail, array $cids, array $mustIds, ?int $diff): ?array
    {
        $cands = [];
        foreach ($state as $id => $s) {
            if ($s['remain'] <= 0 || isset($s['busy'][$day])) {
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
            }
            if (in_array($av, ['稼働可', '希望'], true)) {
                $score += 2;
                $why[] = '出勤可能日〇';
            } else {
                $why[] = '出勤可能日は未入力';
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
