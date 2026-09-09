<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
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
     * 枠を埋める順（＝できる人が少ない役割から）。
     * ⚠ D・SD・MC のような限られた役割を後回しにすると、できる人が別の枠に取られて埋まらない。
     *   日別ボードの POS_PRIORITY と同じ並びにそろえている。
     */
    private const POS_PRIORITY = ['D', 'SD', 'MC', 'OP', 'SP', 'FC', 'RP', 'CK'];

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
     * @param  array<int, string>  $skipDays      自動アサインしない日（'Y-m-d' の並び・2026-09-07 baba要望）
     * @param  array<int, string>  $skipProjects  自動アサインしない案件（案件IDの並び・2026-09-07 baba要望）
     *   ⚠ 「この日は自分で決めたい」「この案件だけは機械に任せない」ときのため。
     *     除いたものは**計画にも出さない**（下見に出ると、入るものだと勘違いする）。
     * @param  array<int, string>  $includeHandMade  「手で入っているけれど、それでも自動で埋めてよい」案件
     *   （2026-09-08 baba指摘「日別ボードで仮で埋めてるものは触らないでほしい」）。
     *   ⚠ 既定は**手で入っている案件には触らない**。ここに案件IDを入れたときだけ対象に戻す。
     */
    public function __construct(
        private string $period,          // 'YYYY-MM'
        private ?string $office = null,  // null＝全拠点
        private array $skipDays = [],
        private array $skipProjects = [],
        private array $includeHandMade = [],
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
        ])->where('status', '!=', 'キャンセル')->get(['project_id', 'staff_id', 'date', 'role']);

        $projectNames = Project::whereIn('id', $existing->pluck('project_id')->unique())
            ->pluck('project_name', 'id');

        $monthCount = [];        // staff_id => その月の件数（案件×日で数える）
        $busyByDay = [];         // 'Y-m-d' => [staff_id => 入っている案件名]
        $memberOf = [];          // project_id => [staff_id => true]
        // ⚠ すでに入っている人の役割も数える。数えないと「MCがもう1人いるのに、また入れる」になる。
        $roleFilled = [];        // project_id => [役割コード => 人数]
        foreach ($existing as $a) {
            $day = Carbon::parse($a->date)->format('Y-m-d');
            $monthCount[$a->staff_id] = ($monthCount[$a->staff_id] ?? 0) + 1;
            $busyByDay[$day][$a->staff_id] = $projectNames[$a->project_id] ?? $a->project_id;
            if (! isset($memberOf[$a->project_id][$a->staff_id])) {
                $roleFilled[$a->project_id][(string) $a->role] = ($roleFilled[$a->project_id][(string) $a->role] ?? 0) + 1;
            }
            $memberOf[$a->project_id][$a->staff_id] = true;
        }

        // 稼働希望（〇／NG）。⚠ 日付は時刻つきで入っているので ShiftWish に任せる。
        $wishByKey = ShiftWish::forDays($people->keys()->all(), $dates);
        // 希望数（充足率の分母）。⚠ 数え方の正本は [[WishCount]] 1か所だけ。
        //   ここで独自に数えると「スタッフ一覧」と食い違う（実際に食い違っていた・2026-09-07 baba指摘）。
        $wishInfo = WishCount::forMonth($this->period, $people->keys()->all());
        $wishDays = array_map(fn ($v) => $v['wish'], $wishInfo);

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

            // ── ⚠ ここからが「ポジションを見て埋める」ところ（2026-09-07 baba指摘で作り直し）──
            //   前は**その人の主ポジションをそのまま付けていた**ので、
            //   「MCが2人いる」「謎解きなのに軍師がいる」が起きていた。
            //   これからは**案件が必要としている枠**（コンテンツ×規模）を作って、そこへ入れる。
            $slots = $this->openSlots($p, $r['short'], $roleFilled[$p->id] ?? []);

            $picks = [];
            $used = [];   // この案件で入れた人（同じ人を2つの枠に入れない）
            foreach ($slots as $role) {
                foreach ($scored as $s) {
                    $sid = $s['id'];
                    if (isset($used[$sid])) {
                        continue;
                    }
                    // ⚠ 役割の決まった枠には「その役割ができる人」だけ。
                    //   できない人を入れると、当日その役割が回らない。
                    if ($role !== '' && ! $this->canDo($s['person'], $role)) {
                        continue;
                    }
                    $used[$sid] = true;
                    $picks[] = [
                        'id' => $sid,
                        'name' => $s['person']->name,
                        // ⚠ 入れる役割は**枠の役割**。その人の主ポジションではない。
                        //   空（''）＝必要ポジションの外の枠＝担当はあとで人が決める。
                        'role' => $role,
                        'score' => $s['ev']['score'],
                        'reasons' => $s['ev']['reasons'],
                        'warnings' => $s['ev']['warnings'],
                    ];
                    // その場で状態を更新＝次の案件では「もう入っている人」になる。
                    $monthCount[$sid] = ($monthCount[$sid] ?? 0) + 1;
                    $addedByStaff[$sid] = ($addedByStaff[$sid] ?? 0) + 1;
                    $busyByDay[$day][$sid] = $p->project_name;
                    $memberOf[$p->id][$sid] = true;
                    $roleFilled[$p->id][$role] = ($roleFilled[$p->id][$role] ?? 0) + 1;
                    break;
                }
            }

            $out[] = [
                // ⚠ 埋めた順番。表示は日ごとに並べ替えるが、
                //   「取り合いが厳しい案件から先に埋めた」ことは画面に残す（2026-09-07）。
                'order' => count($out) + 1,
                'id' => $p->id,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'date' => $day,
                'need' => $r['need'],
                'filled' => $r['filled'],
                'short' => $r['short'],
                'candCount' => $r['candCount'],
                'picks' => $picks,
                // 必要ポジション（コンテンツ×規模）と、今回つくった枠。画面に出す。
                'template' => PositionTemplate::of($p),
                'openSlots' => $slots,
                // 埋めきれない案件は理由を出す（黙って足りないままにしない）。
                'stillShort' => $r['short'] - count($picks),
            ];
        }

        return [
            'projects' => $out,
            'staff' => $this->staffSummary($addedByStaff, $monthCount, $wishInfo, $people),
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

    /**
     * その月の「自動アサインの対象になりうる案件」。**外したものも含めて**返す。
     * 画面の案件ごとのチェックボックスを並べるために使う。
     *
     * ⚠ 外した案件も出さないと、チェックを外して戻すことができなくなる。
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidateProjects(): array
    {
        return $this->targetProjects(false)
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'date' => $p->start_date->format('Y-m-d'),
                'need' => $this->needOf($p),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * ⚠ **人が手でスタッフを入れた案件**（2026-09-08 baba指摘
     * 「日別ボードで仮埋めしてたのに変わった」「触らないでほしい」）。
     *
     * 【なぜ案件まるごと外すのか】
     * 人が一度その案件を組み始めているなら、そこには「この人とこの人を組ませたい」という
     * **人の判断**が入っている。機械が残り枠を埋めると、あとから見て
     * **どこまでが自分の判断だったのか分からなくなる**。だから触らない。
     *
     * 【手で入れた、の見分け方】
     *  ・`auto_run_id` が空＝この機械が入れた行ではない（＝人が入れた）
     *  ・その人が**スタッフ**（people.role='staff'）
     *    ⚠ 社員の行（D決めで決めたD・SD）は数えない。
     *      Dはたいてい先に決まるので、それで外すと**ほとんどの案件が対象外**になり、
     *      この機能そのものが使えなくなる。
     *  ・キャンセル以外
     *
     * @return array<string, array<int, string>> 案件ID => 手で入っている人の名前
     */
    public function handMadeStaff(): array
    {
        $rows = Assignment::whereBetween('date', [
            $this->monthStart->format('Y-m-d').' 00:00:00',
            $this->monthEnd->format('Y-m-d').' 23:59:59',
        ])
            ->whereNull('auto_run_id')
            ->where('status', '!=', 'キャンセル')
            ->whereIn('staff_id', Person::staff()->select('id'))
            ->get(['project_id', 'staff_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Person::whereIn('id', $rows->pluck('staff_id')->unique())->pluck('name', 'id');

        $out = [];
        foreach ($rows as $r) {
            $out[$r->project_id][] = (string) ($names[$r->staff_id] ?? $r->staff_id);
        }

        return array_map(fn (array $v) => array_values(array_unique($v)), $out);
    }

    /**
     * 手で入っているので外した案件（画面に出して、戻せるようにするためのもの）。
     *
     * ⚠ 黙って外すと「なぜこの案件が下見に出てこないのか」が分からなくなる。
     *   必ず画面に理由つきで出す。
     *
     * @return array<int, array<string, mixed>>
     */
    public function handMadeProjects(): array
    {
        $hand = $this->handMadeStaff();
        if ($hand === []) {
            return [];
        }

        return $this->targetProjects(false)
            ->filter(fn (Project $p) => isset($hand[$p->id]))
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'date' => $p->start_date->format('Y-m-d'),
                'need' => $this->needOf($p),
                'people' => $hand[$p->id],
                // いま対象に戻してあるか（チェックを付けた案件）。
                'included' => in_array((string) $p->id, $this->includeHandMade, true),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * その月の案件（拠点で絞ったもの）。中止・開催日なしは除く。
     * ⚠ 対象を決める入口はここ1つ。別の場所で月や拠点の絞り方を書き直さないこと。
     */
    private function monthProjects(): Collection
    {
        return OfficeScope::applyToProjects(Project::query(), $this->office)
            ->notCancelled()
            ->whereNotNull('start_date')
            ->whereBetween('start_date', [
                $this->monthStart->format('Y-m-d').' 00:00:00',
                $this->monthEnd->format('Y-m-d').' 23:59:59',
            ])
            ->orderBy('start_date')
            ->get();
    }

    /**
     * **まだスタッフに公開していないので、自動アサインの対象にしない案件**（2026-09-09 baba要望）。
     *
     * ⚠ 黙って外すと「なぜこの案件が下見に出てこないのか」が分からなくなる。
     *   handMadeProjects と同じく、必ず画面に理由つきで出す。
     *
     * @return array<int, array<string, mixed>>
     */
    public function unpublishedProjects(): array
    {
        return $this->monthProjects()
            ->filter(fn (Project $p) => ! $p->staff_published
                && ! in_array($p->status, ['下書き', '完了'], true)
                && $p->is_archived !== true)
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'date' => $p->start_date->format('Y-m-d'),
                'need' => $this->needOf($p),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    /** その月の「まだ足りない案件」。$applySkip=false なら外した日・案件を無視する。 */
    private function targetProjects(bool $applySkip = true): Collection
    {
        // ⚠ 手で入っている案件（人が組み始めた案件）は外す。1回だけ引いて使い回す。
        $hand = $applySkip ? $this->handMadeStaff() : [];

        return $this->monthProjects()
            ->filter(function (Project $p) use ($applySkip, $hand) {
                // ⚠ 「この日はアサインしない」「この案件は入れない」で外したもの（2026-09-07 baba要望）。
                if ($applySkip && (
                    in_array($p->start_date->format('Y-m-d'), $this->skipDays, true)
                    || in_array((string) $p->id, $this->skipProjects, true)
                )) {
                    return false;
                }
                // ⚠ 人が手でスタッフを入れた案件には触らない（2026-09-08 baba指摘）。
                //    「この案件も自動で埋める」にチェックを付けたときだけ対象に戻す。
                if ($applySkip
                    && isset($hand[$p->id])
                    && ! in_array((string) $p->id, $this->includeHandMade, true)) {
                    return false;
                }
                if (in_array($p->status, ['下書き', '完了'], true) || $p->is_archived === true) {
                    return false;
                }
                // ⚠ **スタッフに公開していない案件は埋めない**（2026-09-09 baba要望）。
                //    公開していない＝まだ募集を出していない＝**エントリー（手を挙げた人）が集まっていない**。
                //    そこへ機械が人を入れると、本人が知らないうちに予定を押さえたことになり、
                //    公開したときには枠が埋まっていて希望を出す意味が無くなる。
                //    ⇒ 公開ボードで「公開する」を押した案件だけを自動アサインの対象にする。
                if (! $p->staff_published) {
                    return false;
                }
                // ⚠ 「🔒 この人数で足りている」で締めた案件（公開ずみ・募集オフ）には触らない。
                //    足りていると人が決めたものに、あとから機械が足さない。
                if (! $p->is_recruiting) {
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
     * この案件で「まだ空いている枠」を、埋める順に並べて返す（2026-09-07）。
     *
     * 例）必要＝D1・MC1・OP2、すでにDが1人 → ['MC','OP','OP']
     *
     * ⚠ 必要ポジションに無い役割は**枠を作らない**。作ると「謎解きなのに軍師」が起きる。
     * ⚠ 必要ポジションの合計より運営人数（need）のほうが多いときは、余りを **空（''）** の枠にする。
     *   空＝担当はあとで人が決める。ここで勝手に役割を付けない。
     * ⚠ コンテンツ・規模が未入力で必要ポジションが分からない案件は、**全部 空の枠**にする
     *   （分からないのに役割を決めない）。
     * ⚠ 埋める順は「できる人が少ない役割から」。D・MC を後回しにすると埋まらない。
     *
     * @param  array<string, int>  $filledByRole すでに入っている人の役割ごとの人数
     * @return array<int, string>  役割コードの並び（''＝役割の決まっていない枠）
     */
    private function openSlots(Project $p, int $short, array $filledByRole): array
    {
        $template = PositionTemplate::of($p);

        $slots = [];
        foreach ($template as $role => $count) {
            $rest = $count - (int) ($filledByRole[$role] ?? 0);
            for ($i = 0; $i < $rest; $i++) {
                $slots[] = $role;
            }
        }

        // ⚠ できる人が少ない役割から埋める（D・SD・MC など）。
        //   あとに回すと、できる人が別の枠に取られて埋まらない。
        $rank = array_flip(self::POS_PRIORITY);
        usort($slots, fn ($a, $b) => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));

        // 運営人数より多いぶんは切る。足りないぶんは「役割の決まっていない枠」。
        $slots = array_slice($slots, 0, $short);
        while (count($slots) < $short) {
            $slots[] = '';
        }

        return $slots;
    }

    /** その人がその役割をできるか（staff_role_eligibility）。空の枠は誰でも入れる。 */
    private function canDo(Person $p, string $role): bool
    {
        if ($role === '') {
            return true;
        }
        $can = $p->relationLoaded('roleEligibilities')
            ? $p->roleEligibilities->pluck('position')->all()
            : [];

        return in_array($role, $can, true);
    }

    /**
     * 人ごとの「before → after」。プレビューで平準化の効き具合を見せるためのもの。
     *
     * @return array<int, array<string, mixed>>
     */
    private function staffSummary(array $addedByStaff, array $monthCountAfter, array $wishInfo, Collection $people): array
    {
        $out = [];
        foreach ($addedByStaff as $sid => $add) {
            $after = (int) ($monthCountAfter[$sid] ?? 0);
            $before = $after - $add;
            $mine = $wishInfo[$sid] ?? ['wish' => 0, 'okDays' => 0, 'entries' => 0];
            $want = (int) $mine['wish'];
            $out[] = [
                'id' => $sid,
                'name' => $people[$sid]->name ?? $sid,
                'wishDays' => $want,
                // 内訳（画面に「11（〇8日・応募10件）」と出すため）。
                // ⚠ 数字だけだと何を数えたのか分からない＝聞かれるたびに説明することになる。
                'okDays' => (int) $mine['okDays'],
                'entries' => (int) $mine['entries'],
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
