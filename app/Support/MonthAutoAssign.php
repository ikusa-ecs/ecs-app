<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 月まとめの自動アサイン「計画づくり」（2026-09-07 baba要望）。
 *
 * 【なぜ1件ずつではなく月まとめなのか】
 * 案件を1件ずつ埋めると **早い者勝ち** になる。先に処理した案件が人を取ってしまい、
 * 希望者の少ない案件が最後に回ると埋まらない。
 * また「今月0件の人に加点」だけでは平準化として粗く、0件を脱した瞬間に
 * 1件の人も10件の人も同じ扱いになってしまう。
 *
 * 【この部品がやること】
 *  ① その月の「まだ足りない案件」を集める
 *  ② **取り合いが厳しい案件から先に**埋める（候補数 ÷ 不足数 が小さい順）
 *  ③ 1人入れるたびに その人の今月件数・充足率を数え直す ＝ 自然にならされる
 *  ④ 点数は既存の頭脳 [[AssignmentScorer]] ＋「希望充足率が低い人ほど加点」
 *
 * ⚠ **DBを一切書き換えない。** 返すのは「計画」だけ。
 *   保存するかどうかは人がプレビューを見てから決める（設計書11章＝提案は自動・確定は人）。
 * ⚠ 判定の決まりは既存画面とそろえる。ずれると同じ人が画面ごとに出たり出なかったりする。
 *   ・出す人＝スタッフ・在籍中・拠点（「📅 空いている人」と同じ）
 *   ・その日NG／同じ日に別案件／今月上限は入れない
 */
class MonthAutoAssign
{
    /** 1人が1か月に入れる上限（既存のアサイン画面・日別ボードと同じ数）。 */
    public const MONTH_CAP = 20;

    /**
     * 「希望充足率が低い人ほど加点」の最大点。
     *
     * ⚠ AssignmentScorer の「本人が希望＝35点」より少し弱く置く。
     *   強くしすぎると、希望を出していない人ばかり選ばれて本末転倒になる。
     */
    private const FAIRNESS_MAX = 25;

    private Carbon $monthStart;

    private Carbon $monthEnd;

    /**
     * @param  array<int, string>  $skipDays  自動アサインしない日（'Y-m-d' の並び・2026-09-07 baba要望）
     *   ⚠ 「この日は自分で決めたい」「大事な案件だから機械に任せない」ときのため。
     *     除いた日の案件は**計画にも出さない**（下見に出ると、入るものだと勘違いする）。
     */
    public function __construct(
        private string $period,          // 'YYYY-MM'
        private ?string $office = null,  // null＝全拠点
        private array $skipDays = [],
    ) {
        [$y, $m] = array_map('intval', explode('-', $period));
        $this->monthStart = Carbon::create($y, $m, 1)->startOfDay();
        $this->monthEnd = $this->monthStart->copy()->endOfMonth();
    }

    /**
     * 計画をつくる（DBは書き換えない）。
     *
     * @return array{projects: array<int, array<string, mixed>>, staff: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function plan(): array
    {
        $projects = $this->targetProjects();
        if ($projects->isEmpty()) {
            return ['projects' => [], 'staff' => [], 'totals' => $this->totals(0, 0, 0)];
        }

        $dates = $projects->map(fn (Project $p) => $p->start_date->format('Y-m-d'))->unique()->values()->all();

        // ── 材料をまとめて引く（案件ごとに問い合わせない）──────────────
        $people = $this->candidatePeople();
        $apps = Application::whereIn('project_id', $projects->pluck('id'))
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->all())
            ->all();

        // その月の既存アサイン（キャンセル以外）。件数と「その日どこに入っているか」を作る。
        $existing = Assignment::whereBetween('date', [
            $this->monthStart->format('Y-m-d').' 00:00:00',
            $this->monthEnd->format('Y-m-d').' 23:59:59',
        ])->where('status', '!=', 'キャンセル')->get(['project_id', 'staff_id', 'date']);

        $projectNames = Project::whereIn('id', $existing->pluck('project_id')->unique())
            ->pluck('project_name', 'id');

        $monthCount = [];        // staff_id => その月の件数（案件×日で数える）
        $busyByDay = [];         // 'Y-m-d' => [staff_id => 入っている案件名]
        $memberOf = [];          // project_id => [staff_id => true]
        foreach ($existing as $a) {
            $day = Carbon::parse($a->date)->format('Y-m-d');
            $monthCount[$a->staff_id] = ($monthCount[$a->staff_id] ?? 0) + 1;
            $busyByDay[$day][$a->staff_id] = $projectNames[$a->project_id] ?? $a->project_id;
            $memberOf[$a->project_id][$a->staff_id] = true;
        }

        // 稼働希望（〇／NG）。⚠ 日付は時刻つきで入っているので ShiftWish に任せる。
        $wishByKey = ShiftWish::forDays($people->keys()->all(), $dates);
        // 希望日数（分母）＝その月に「〇」を出した日数。充足率の計算に使う。
        $wishDays = $this->wishDaysOfMonth($people->keys()->all());

        // ── ② 取り合いが厳しい案件から先に ─────────────────────────
        $rows = $projects->map(function (Project $p) use ($apps, $memberOf, $people, $wishByKey, $busyByDay, $monthCount) {
            $need = $this->needOf($p);
            $filled = count($memberOf[$p->id] ?? []);
            $short = max(0, $need - $filled);
            $cand = $this->candidatesFor($p, $apps, $memberOf, $people, $wishByKey, $busyByDay, $monthCount);

            return [
                'project' => $p,
                'need' => $need,
                'filled' => $filled,
                'short' => $short,
                'candCount' => count($cand),
                // 小さいほど厳しい＝先に埋める。候補0件は「どうやっても埋まらない」ので最後でよい。
                'tightness' => $short > 0 && count($cand) > 0 ? count($cand) / $short : PHP_INT_MAX,
            ];
        })->filter(fn (array $r) => $r['short'] > 0)->values();

        $rows = $rows->sortBy([
            fn ($a, $b) => $a['tightness'] <=> $b['tightness'],
            fn ($a, $b) => $a['project']->start_date <=> $b['project']->start_date,
        ])->values();

        // ── ③④ 順に埋める（入れるたびに件数・充足率を数え直す）──────────
        $addedByStaff = [];
        $out = [];
        foreach ($rows as $r) {
            /** @var Project $p */
            $p = $r['project'];
            $day = $p->start_date->format('Y-m-d');

            $cand = $this->candidatesFor($p, $apps, $memberOf, $people, $wishByKey, $busyByDay, $monthCount);
            $scorer = $this->scorerFor($p, $wishByKey, $busyByDay, $monthCount, $memberOf, $people);

            $scored = [];
            foreach ($cand as $sid) {
                $person = $people[$sid];
                $ev = $scorer->evaluate($person);
                if ($ev['blocked']) {
                    continue;   // この日NG・NGペア同席（頭脳が外した人）
                }
                $ev['score'] += $this->fairnessBonus($sid, $monthCount, $addedByStaff, $wishDays);
                $scored[] = ['id' => $sid, 'person' => $person, 'ev' => $ev];
            }
            usort($scored, fn ($a, $b) => $b['ev']['score'] <=> $a['ev']['score']);

            $picks = [];
            foreach (array_slice($scored, 0, $r['short']) as $s) {
                $sid = $s['id'];
                $picks[] = [
                    'id' => $sid,
                    'name' => $s['person']->name,
                    'role' => $this->roleOf($s['person']),
                    'score' => $s['ev']['score'],
                    'reasons' => $s['ev']['reasons'],
                    'warnings' => $s['ev']['warnings'],
                ];
                // その場で状態を更新＝次の案件では「もう入っている人」になる。
                $monthCount[$sid] = ($monthCount[$sid] ?? 0) + 1;
                $addedByStaff[$sid] = ($addedByStaff[$sid] ?? 0) + 1;
                $busyByDay[$day][$sid] = $p->project_name;
                $memberOf[$p->id][$sid] = true;
            }

            $out[] = [
                'id' => $p->id,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'date' => $day,
                'need' => $r['need'],
                'filled' => $r['filled'],
                'short' => $r['short'],
                'candCount' => $r['candCount'],
                'picks' => $picks,
                // 埋めきれない案件は理由を出す（黙って足りないままにしない）。
                'stillShort' => $r['short'] - count($picks),
            ];
        }

        return [
            'projects' => $out,
            'staff' => $this->staffSummary($addedByStaff, $monthCount, $wishDays, $people),
            'totals' => $this->totals(
                count($out),
                array_sum(array_map(fn ($r) => count($r['picks']), $out)),
                array_sum(array_map(fn ($r) => $r['stillShort'], $out)),
            ),
        ];
    }

    /**
     * その月の「自動アサインの対象になりうる日」。**除外した日も含めて**返す。
     * 画面のチェックボックス（この日はアサインしない）を並べるために使う。
     *
     * ⚠ 除外した日も出さないと、チェックを外して戻すことができなくなる。
     *
     * @return array<string, int> 'Y-m-d' => その日の対象案件数
     */
    public function candidateDays(): array
    {
        $out = [];
        foreach ($this->targetProjects(false) as $p) {
            $d = $p->start_date->format('Y-m-d');
            $out[$d] = ($out[$d] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /** その月の「まだ足りない案件」。$applySkip=false なら「この日はアサインしない」を無視する。 */
    private function targetProjects(bool $applySkip = true): Collection
    {
        return OfficeScope::applyToProjects(Project::query(), $this->office)
            ->notCancelled()
            ->whereNotNull('start_date')
            ->whereBetween('start_date', [
                $this->monthStart->format('Y-m-d').' 00:00:00',
                $this->monthEnd->format('Y-m-d').' 23:59:59',
            ])
            ->orderBy('start_date')
            ->get()
            ->filter(function (Project $p) use ($applySkip) {
                // ⚠ 「この日はアサインしない」で外した日（2026-09-07 baba要望）。
                if ($applySkip && in_array($p->start_date->format('Y-m-d'), $this->skipDays, true)) {
                    return false;
                }
                if (in_array($p->status, ['下書き', '完了'], true) || $p->is_archived === true) {
                    return false;
                }
                // ⚠ 「🔒 この人数で足りている」で締めた案件（公開ずみ・募集オフ）には触らない。
                //    足りていると人が決めたものに、あとから機械が足さない。
                if ($p->staff_published && ! $p->is_recruiting) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /** 候補に出してよい人（スタッフ・在籍中・拠点）。 */
    private function candidatePeople(): Collection
    {
        return OfficeScope::applyToPeople(Person::staff(), $this->office)
            ->where(fn ($q) => $q->where('active', true)->orWhereNull('active'))
            ->with(['roleEligibilities', 'ngRelations'])
            ->get()
            ->keyBy('id');
    }

    /** 運営人数。未入力（0）は「決まっていない」ので対象外にする（勝手に埋めない）。 */
    private function needOf(Project $p): int
    {
        return (int) ($p->required_count ?? 0);
    }

    /**
     * この案件に入れられる人。
     * ＝（この案件にエントリー ∪ その日〇）− すでにメンバー − その日NG − 同じ日に別案件 − 今月上限。
     *
     * @return array<int, string>
     */
    private function candidatesFor(
        Project $p, array $apps, array $memberOf, Collection $people,
        array $wishByKey, array $busyByDay, array $monthCount,
    ): array {
        $day = $p->start_date->format('Y-m-d');
        $pool = array_unique(array_merge(
            $apps[$p->id] ?? [],
            array_keys(array_filter(
                $people->keys()->flip()->all(),
                fn ($_, $sid) => ($wishByKey[$sid.'|'.$day] ?? null) === 'ok',
                ARRAY_FILTER_USE_BOTH
            )),
        ));

        return array_values(array_filter($pool, function ($sid) use ($people, $memberOf, $p, $wishByKey, $day, $busyByDay, $monthCount) {
            if (! $people->has($sid)) {
                return false;   // 社員・退職者・他拠点
            }
            if (isset($memberOf[$p->id][$sid])) {
                return false;   // すでにこの案件のメンバー
            }
            if (($wishByKey[$sid.'|'.$day] ?? null) === 'ng') {
                return false;   // その日は本人がNG
            }
            if (isset($busyByDay[$day][$sid])) {
                return false;   // 同じ日に別の案件へ入っている
            }
            if (($monthCount[$sid] ?? 0) >= self::MONTH_CAP) {
                return false;   // 今月の上限
            }

            return true;
        }));
    }

    /** 既存の頭脳（AssignmentScorer）を、この案件ぶん組み立てる。 */
    private function scorerFor(
        Project $p, array $wishByKey, array $busyByDay, array $monthCount,
        array $memberOf, Collection $people,
    ): AssignmentScorer {
        $day = $p->start_date->format('Y-m-d');

        // その日「別案件に入っている人」＝頭脳の減点材料（ここでは候補から外しているので通常は空）。
        $sameDay = [];
        foreach (($busyByDay[$day] ?? []) as $sid => $name) {
            $sameDay[$sid] = [$name];
        }

        // 本人の希望（'希望'/'稼働可'/'NG'）。頭脳はこの言葉で受け取る。
        $wish = [];
        foreach ($people as $sid => $_) {
            $code = $wishByKey[$sid.'|'.$day] ?? null;
            if ($code === 'ok') {
                $wish[$sid] = '稼働可';
            } elseif ($code === 'ng') {
                $wish[$sid] = 'NG';
            }
        }

        $contentIds = is_array($p->content_ids) ? array_filter($p->content_ids) : [];
        $contentNames = $contentIds ? Content::whereIn('id', $contentIds)->pluck('content_name')->all() : [];

        // リピート案件の継続性＝同じクライアントの過去案件に出ていた人。
        $repeatIds = [];
        if ($p->is_repeat && $p->client) {
            $pastIds = Project::where('client', $p->client)->where('id', '!=', $p->id)->pluck('id');
            $repeatIds = $pastIds->isEmpty() ? [] : Assignment::whereIn('project_id', $pastIds)
                ->pluck('staff_id')->unique()->values()->all();
        }

        $memberNames = $people->only(array_keys($memberOf[$p->id] ?? []))->pluck('name')->all();

        return (new AssignmentScorer(
            $p, $p->start_date, $wish, $sameDay, $monthCount, $repeatIds, $contentNames, self::MONTH_CAP
        ))->setProjectMemberNames($memberNames);
    }

    /**
     * 「希望充足率が低い人ほど加点」。
     *
     * 充足率＝（すでに入っている件数＋この計画で入れた件数）÷ その月に〇を出した日数。
     * ⚠ 〇を1日も出していない人は加点しない（0で割れないうえ、
     *   「働きたいと言っていない人」を優先すると本末転倒になる）。
     */
    private function fairnessBonus(string $sid, array $monthCount, array $addedByStaff, array $wishDays): float
    {
        $want = (int) ($wishDays[$sid] ?? 0);
        if ($want <= 0) {
            return 0.0;
        }
        $have = (int) ($monthCount[$sid] ?? 0) + (int) ($addedByStaff[$sid] ?? 0);
        $rate = min(1.0, $have / $want);

        return round((1 - $rate) * self::FAIRNESS_MAX, 2);
    }

    /**
     * その月に「〇」を出した日数（充足率の分母）。
     *
     * @return array<string, int>
     */
    private function wishDaysOfMonth(array $staffIds): array
    {
        if (! $staffIds) {
            return [];
        }
        $rows = ShiftPreference::whereIn('staff_id', $staffIds)
            ->whereBetween('date', [
                $this->monthStart->format('Y-m-d').' 00:00:00',
                $this->monthEnd->format('Y-m-d').' 23:59:59',
            ])
            ->get(['staff_id', 'date', 'availability']);

        $out = [];
        foreach ($rows as $r) {
            if (! in_array((string) $r->availability, ['稼働可', '希望'], true)) {
                continue;
            }
            $out[$r->staff_id] = ($out[$r->staff_id] ?? 0) + 1;
        }

        return $out;
    }

    /** 主ポジション（保存する役割コード）。決められなければ空（あとで人が入れる）。 */
    private function roleOf(Person $p): string
    {
        $can = $p->relationLoaded('roleEligibilities')
            ? $p->roleEligibilities->pluck('position')->all()
            : [];
        foreach (['D', 'SD', 'MC', 'OP', 'SP', 'FC', 'RP', 'CK'] as $key) {
            if (in_array($key, $can, true)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * 人ごとの「before → after」。プレビューで平準化の効き具合を見せるためのもの。
     *
     * @return array<int, array<string, mixed>>
     */
    private function staffSummary(array $addedByStaff, array $monthCountAfter, array $wishDays, Collection $people): array
    {
        $out = [];
        foreach ($addedByStaff as $sid => $add) {
            $after = (int) ($monthCountAfter[$sid] ?? 0);
            $before = $after - $add;
            $want = (int) ($wishDays[$sid] ?? 0);
            $out[] = [
                'id' => $sid,
                'name' => $people[$sid]->name ?? $sid,
                'wishDays' => $want,
                'before' => $before,
                'add' => $add,
                'after' => $after,
                'rateBefore' => $want > 0 ? (int) round($before / $want * 100) : null,
                'rateAfter' => $want > 0 ? (int) round($after / $want * 100) : null,
            ];
        }
        usort($out, fn ($a, $b) => $b['add'] <=> $a['add'] ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return array<string, int> */
    private function totals(int $projects, int $added, int $stillShort): array
    {
        return ['projects' => $projects, 'added' => $added, 'stillShort' => $stillShort];
    }
}
