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

    /** FCに入れる案。 */
    private static function picks(Carbon $month, ?string $office, array $state, array $map, array $names): array
    {
        if ($state === []) {
            return [];
        }
        $from = Carbon::today()->max($month)->format('Y-m-d');
        $to = $month->copy()->endOfMonth()->format('Y-m-d').' 23:59:59';

        $cases = OfficeScope::hideTakenOver(OfficeScope::applyToProjects(Project::query(), $office), $office, true)
            ->notCancelled()->needsAssign()
            ->whereNotNull('start_date')->whereBetween('start_date', [$from, $to])
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')->orderBy('start_time')->get();
        if ($cases->isEmpty()) {
            return [];
        }

        $assigned = Assignment::whereIn('project_id', $cases->pluck('id'))->where('status', '!=', 'キャンセル')->get()->groupBy('project_id');
        $dispatch = DispatchRows::liveCountsFor($cases->pluck('id'));
        $avail = ShiftPreference::whereIn('staff_id', array_keys($state))
            ->whereBetween('date', [$from, $to])->get()
            ->mapWithKeys(fn ($s) => [$s->staff_id.'|'.Carbon::parse($s->date)->format('Y-m-d') => $s->availability]);
        $mustIds = ContentDifficulty::whereNotNull('content_id')
            ->where(fn ($q) => $q->where('must', true)->orWhere('recommend', true))->pluck('content_id')->flip()->all();

        $out = [];
        foreach ($cases as $c) {
            $day = $c->start_date->format('Y-m-d');
            $rows = $assigned->get($c->id, collect());
            $filled = $rows->pluck('staff_id')->unique()->count() + ($dispatch[$c->id] ?? 0);
            $need = RecruitStatus::need($c->required_count);
            $already = $rows->filter(fn ($a) => isset($state[$a->staff_id]))->pluck('staff_id')->unique()->count();
            $cap = self::capFor($c, $need) - $already;
            if ($filled >= $need || $cap <= 0) {
                continue;   // 空きが無い／新人がもう上限まで入っている
            }
            $cids = is_array($c->content_ids) ? array_map('strval', $c->content_ids) : [];
            $diff = RookieDifficulty::ofProject($c, $map);

            // 空きと上限の数だけ、1人ずつ選ぶ（選んだ人はその日もう入れない）。
            for ($slot = 0; $slot < min($cap, $need - $filled); $slot++) {
                $pick = self::pickOne($state, $day, $avail, $cids, $mustIds, $diff);
                if ($pick === null) {
                    break;
                }
                [$best, $cands] = $pick;
                $state[$best['id']]['remain']--;
                $state[$best['id']]['busy'][$day] = true;
                foreach ($cids as $x) {
                    $state[$best['id']]['fcDone'][$x] = ($state[$best['id']]['fcDone'][$x] ?? 0) + 1;
                }

                $out[] = [
                    'date' => $day,
                    'projectId' => $c->id,
                    'name' => ProjectContentName::of($c, $names, (string) $c->project_name),
                    'client' => (string) ($c->client ?? ''),
                    'difficulty' => $diff,
                    'room' => $need - $filled - $slot,
                    'rookie' => $state[$best['id']]['person']->name,
                    'rookieId' => $best['id'],
                    'why' => implode('・', $best['why']),
                    'others' => array_map(fn ($o) => $state[$o['id']]['person']->name, array_slice($cands, 1, 2)),
                ];
            }
        }

        return $out;
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
