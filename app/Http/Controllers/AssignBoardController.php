<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * アサイン系の画面（/assign・/entries・/pickup）。
 *
 * スタッフ名の一覧（NAME_POOL の単一ソース）を DB（people のスタッフ）から渡す。
 *
 * さらに /assign（日別ボード）は、案件リストと「割当メンバー」を本物のデータにする：
 *   - 案件＝projects（今日〜21日先の本番・予備日）
 *   - 割当メンバー＝assignments（/project-assign・/assign-director で保存した実データ）
 * Blade は window.ECS_BOARD_CASES があればそれを使い、空なら従来の見本(cases.js)合成にフォールバックする。
 *
 * ※ 希望者カラム・今月件数・稼働可数はまだ見本（応募/カレンダー〇のデータ源は別途＝第3段）。
 */
class AssignBoardController extends Controller
{
    /** 役割キー → ボード表示用のポジション名（assignments.role の値に対応）。 */
    private const POS_LABELS = [
        'D' => 'D', 'SD' => 'SD', 'MC' => 'MC', 'OP' => 'OP',
        'FC' => 'FC', 'CK' => 'CK', 'UKE' => '受付', 'GUN' => '軍師・サポーター',
    ];

    /** 主ポジションを選ぶ優先順（重要・経験者向けの役割を上に）。 */
    private const POS_PRIORITY = ['D', 'SD', 'MC', 'OP', 'GUN', 'FC', 'UKE', 'CK'];

    /** アサインボード（日別）/assign。案件＋割当メンバー＋希望者・稼働可・今月件数を DB から渡す。
     *  ?from=YYYY-MM-DD が来たら、その日を基準（先頭）に 3週間分を表示する（既定＝今日）。 */
    public function assign(Request $request)
    {
        $anchor = $this->boardAnchor($request);

        return view('assign', [
            'staffPool' => $this->staffPool(),
            'boardCases' => $this->boardCases($anchor),
            'boardAvail' => $this->boardAvail($anchor),       // off → その日に稼働可/希望のスタッフ一覧
            'boardMonth' => $this->boardMonthCount($anchor),  // 名前 → ボード期間のアサイン件数（上限バッジ用）
            'anchor' => $anchor->format('Y-m-d'),             // 画面の基準日（日付ピッカーの初期値・日付計算の起点）
        ]);
    }

    /** 表示の基準日。?from=YYYY-MM-DD が正しい日付ならその日、無ければ今日。 */
    private function boardAnchor(Request $request): Carbon
    {
        $from = (string) $request->query('from', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
            } catch (\Throwable $e) {
                // 不正な日付は今日に倒す
            }
        }

        return Carbon::today();
    }

    /** エントリー一覧 /entries。案件＋応募者（applications）を DB から渡す。 */
    public function entries()
    {
        return view('entries', [
            'staffPool' => $this->staffPool(),
            'entriesCases' => $this->entriesCases(),
        ]);
    }

    /** ピックアップ /pickup。案件＋候補者（応募＋当日稼働可）＋現メンバーを DB から渡す。 */
    public function pickup()
    {
        return view('pickup', [
            'staffPool' => $this->staffPool(),
            'pickupCases' => $this->pickupCases(),
        ]);
    }

    /**
     * スタッフ名の一覧（経験回数の多い順）。NAME_POOL の単一ソース。
     *
     * @return list<string>
     */
    private function staffPool(): array
    {
        return Person::staff()
            ->orderByDesc('experience_count')
            ->pluck('name')
            ->all();
    }

    /**
     * ボード用の案件データ（割当メンバー込み）を DB から組み立てる。
     * Blade の前処理済みの形（id/off/name/.../assigned）に合わせて返す。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function boardCases(Carbon $anchor): Collection
    {
        // ボード対象＝完了/下書きでなく、開催日があり、基準日〜21日先の案件。
        $projects = Project::orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date && ! in_array($p->status, ['完了', '下書き'], true))
            ->map(fn (Project $p) => [$p, $this->offDays($p->start_date, $anchor)])
            ->filter(fn (array $pair) => $pair[1] >= 0 && $pair[1] <= 21)
            ->values();

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->map(fn (array $pair) => $pair[0]->id);

        // この案件群の割当（キャンセル以外）。
        $assignments = Assignment::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'role']);

        // この案件群への応募（applications）＝希望者カラムの元。
        $apps = Application::whereIn('project_id', $projectIds)->get(['project_id', 'staff_id']);

        // 関係する人（名前・区分・できる役割）をまとめて引く。
        $people = $this->peopleWithPos(
            $assignments->pluck('staff_id')->merge($apps->pluck('staff_id'))->unique()->all()
        );

        $assignedByProject = $assignments->groupBy('project_id');
        $appsByProject = $apps->groupBy('project_id');

        return $projects->map(function (array $pair) use ($assignedByProject, $appsByProject, $people) {
            [$p, $off] = $pair;

            // 割当メンバー（assignments → 表示用 {name, lv, pos, type}）。
            $assigned = ($assignedByProject->get($p->id) ?? collect())
                ->map(function ($a) use ($people) {
                    $person = $people->get($a->staff_id);

                    return [
                        'name' => $person->name ?? $a->staff_id,
                        'lv' => '-',   // 経験レベルは希望者カラム用。メンバー行では未表示。
                        'pos' => self::POS_LABELS[$a->role] ?? ($a->role ?: '—'),
                        'type' => ($person && $person->role === 'employee') ? 'emp' : 'staff',
                    ];
                })
                ->values()
                ->all();

            // 応募者（applications → 希望者カラム用 {name, lv, pos}）。cal は画面側で当日の稼働可と突合。
            $applicants = ($appsByProject->get($p->id) ?? collect())
                ->pluck('staff_id')->unique()->values()
                ->map(function ($sid) use ($people) {
                    $person = $people->get($sid);

                    return [
                        'name' => $person->name ?? $sid,
                        'lv' => $this->lvCode(optional($person)->skill_level),
                        'pos' => $this->primaryPos($person),
                    ];
                })->all();

            // tags（「連勤/設営/前泊/日目」を含めると Blade 側で色分けされる）。
            $tags = [];
            if (($p->date_type ?? '本番') !== '本番') {
                $tags[] = $p->date_type;
            }
            if ($p->lodging && str_contains($p->lodging, '前泊')) {
                $tags[] = '前泊';
            }
            if ($p->is_repeat) {
                $tags[] = 'リピート';
            }

            $state = $this->boardState($p);
            $salesOwners = is_array($p->sales_owners) ? $p->sales_owners : [];

            return [
                'id' => $p->id,
                'off' => $off,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'cat' => $p->site_category ?: '通常',
                'need' => $p->required_count ?? 0,
                'filled' => count($assigned),
                'state' => $state,
                'mine' => in_array('baba', $salesOwners, true),
                'meet' => $p->start_time ?? '—',
                'leave' => $p->end_time ?? '—',
                'enter' => $p->event_enter_time ?? '—',
                'evStart' => $p->event_start_time ?? '—',
                'evEnd' => $p->event_end_time ?? '—',
                'place' => $p->location ?? '',
                'placeShort' => $p->location ?? '',
                'meetPlace' => $p->assembly_type ?? '',
                'tags' => $tags,
                'pos' => [],   // ポジション充足ランプは次段（まずメンバー実データを優先）。
                'archived' => false,
                'draft' => false,
                'assigned' => $assigned,
                'applicants' => $applicants,   // 希望者カラムの元（応募者）
            ];
        })->values();
    }

    /**
     * ボードの各日（off）の「稼働可/希望スタッフ一覧」を shift_preferences から作る。
     * 返り値＝ off(整数) => [{name, lv, pos}, ...]。希望者カラムの「終日〇」と稼働可人数に使う。
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private function boardAvail(Carbon $anchor): array
    {
        $end = $anchor->copy()->addDays(21);

        $prefs = ShiftPreference::whereBetween('date', [$anchor->format('Y-m-d'), $end->format('Y-m-d')])
            ->whereIn('availability', ['稼働可', '希望'])
            ->get(['staff_id', 'date']);

        if ($prefs->isEmpty()) {
            return [];
        }

        $people = $this->peopleWithPos($prefs->pluck('staff_id')->unique()->all());

        $out = [];
        foreach ($prefs as $pref) {
            $off = $this->offDays($pref->date, $anchor);
            $person = $people->get($pref->staff_id);
            $out[$off][] = [
                'name' => $person->name ?? $pref->staff_id,
                'lv' => $this->lvCode(optional($person)->skill_level),
                'pos' => $this->primaryPos($person),
            ];
        }

        return $out;
    }

    /**
     * ボード期間（今日〜21日先）の「名前 → アサイン件数」を assignments から数える。
     * 月20件上限バッジ（capBadge）の表示に使う。キャンセルは除く。
     *
     * @return array<string, int>
     */
    private function boardMonthCount(Carbon $anchor): array
    {
        $end = $anchor->copy()->addDays(21);

        $rows = Assignment::whereBetween('date', [$anchor->format('Y-m-d'), $end->format('Y-m-d')])
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = Person::whereIn('id', $rows->pluck('staff_id')->unique()->all())
            ->pluck('name', 'id');

        $out = [];
        foreach ($rows as $r) {
            $name = $names[$r->staff_id] ?? $r->staff_id;
            $out[$name] = ($out[$name] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * エントリー一覧用の案件データ（応募者込み）を DB から組み立てる。
     * /assign（21日枠）と違い、募集対象を月ごとに広く出すため完了/下書きのみ除外する。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function entriesCases(): Collection
    {
        $today = Carbon::today();

        $projects = Project::orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true));

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->pluck('id');

        // 応募（applications）＝この案件に「エントリーする」を出した人。
        $apps = Application::whereIn('project_id', $projectIds)->get(['project_id', 'staff_id']);
        // 確定/仮アサイン（assignments・キャンセル除く）＝アサイン済みかの判定に使う。
        $assignedRows = Assignment::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id']);

        $people = $this->peopleWithPos(
            $apps->pluck('staff_id')->merge($assignedRows->pluck('staff_id'))->unique()->all()
        );

        $appsByProject = $apps->groupBy('project_id');
        $assignedByProject = $assignedRows->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->all());

        return $projects->map(function (Project $p) use ($today, $appsByProject, $assignedByProject, $people) {
            $assignedIds = $assignedByProject->get($p->id, []);
            $off = $this->offDays($p->start_date ?? $today, $today);

            // 応募者リスト（applications → 表示用 {no, name, lv, pos, assigned}）。
            $entrants = ($appsByProject->get($p->id) ?? collect())
                ->pluck('staff_id')->unique()->values()
                ->map(function ($sid, $i) use ($people, $assignedIds) {
                    $person = $people->get($sid);

                    return [
                        'no' => $i + 1,
                        'name' => $person->name ?? $sid,
                        'lv' => $this->lvCode($person?->skill_level),
                        'pos' => $this->primaryPos($person),
                        'assigned' => in_array($sid, $assignedIds, true),
                    ];
                })->all();

            return [
                'id' => $p->id,
                'off' => $off,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'cat' => $p->site_category ?: '通常',
                'need' => $p->required_count ?? 0,
                'filled' => count($assignedIds),
                'status' => $p->status ?? '未着手',
                'dayType' => $p->date_type ?? '本番',
                'state' => $this->boardState($p),
                'recruit' => (bool) $p->is_recruiting,
                'archived' => $off < 0,   // 過去の案件は出さない（画面の !archived フィルタで除外）
                'draft' => false,
                'entrants' => $entrants,
            ];
        })->values();
    }

    /**
     * ピックアップ用の案件データ（候補者＋現メンバー込み）を DB から組み立てる。
     * 候補者＝応募者（applications）∪ 現メンバー（assignments）。cal＝その日に稼働可/希望を出しているか。
     * メンバー（members）＝assignments のスタッフ名＝rosterの初期値。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pickupCases(): Collection
    {
        $today = Carbon::today();

        $projects = Project::with('director:id,name')
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true));

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->pluck('id');

        $apps = Application::whereIn('project_id', $projectIds)->get(['project_id', 'staff_id']);
        $assignedRows = Assignment::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id']);

        $staffIds = $apps->pluck('staff_id')->merge($assignedRows->pluck('staff_id'))->unique();
        $people = $this->peopleWithPos($staffIds->all());

        // 当日稼働可（shift_preferences の 稼働可/希望）を (staff_id|Y-m-d) のセットにしておく。
        $availSet = [];
        foreach (ShiftPreference::whereIn('staff_id', $staffIds->all())
            ->whereIn('availability', ['稼働可', '希望'])
            ->get(['staff_id', 'date']) as $pref) {
            $availSet[$pref->staff_id . '|' . $pref->date->format('Y-m-d')] = true;
        }

        $appsByProject = $apps->groupBy('project_id')->map(fn ($r) => $r->pluck('staff_id')->all());
        $assignedByProject = $assignedRows->groupBy('project_id')
            ->map(fn ($r) => $r->pluck('staff_id')->unique()->all());

        $contentNames = Content::pluck('content_name', 'id');

        return $projects->map(function (Project $p) use ($today, $appsByProject, $assignedByProject, $availSet, $people, $contentNames) {
            $assignedIds = $assignedByProject->get($p->id, []);
            $applicantIds = $appsByProject->get($p->id, []);
            $off = $this->offDays($p->start_date ?? $today, $today);

            // 候補者プール＝応募者 ∪ 現メンバー（重複は除く）。
            $candIds = collect($applicantIds)->merge($assignedIds)->unique()->values();
            $dateStr = $p->start_date ? $p->start_date->format('Y-m-d') : null;

            $entrants = $candIds->map(function ($sid) use ($people, $availSet, $dateStr) {
                $person = $people->get($sid);

                return [
                    'name' => $person->name ?? $sid,
                    'pos' => $this->primaryPos($person),
                    'cal' => $dateStr ? isset($availSet[$sid . '|' . $dateStr]) : false,
                ];
            })->all();

            // メンバー名（assignments）＝rosterの初期値。
            $memberNames = collect($assignedIds)
                ->map(fn ($sid) => optional($people->get($sid))->name ?? $sid)
                ->values()->all();

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId ? ($contentNames[$firstContentId] ?? $p->project_name) : $p->project_name;

            return [
                'id' => $p->id,
                'off' => $off,
                'content' => $content,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'fmt' => $this->fmtCode($p->format),
                'dayType' => $p->date_type ?? '本番',
                'parentId' => $p->parent_project_id,
                'need' => $p->required_count ?? 0,
                'filled' => count($assignedIds),
                'meet' => $p->start_time ?? '—',
                'leave' => $p->end_time ?? '—',
                'place' => $p->location ?? '',
                'dir' => optional($p->director)->name ?? '未定',
                'guests' => $p->guest_count ?? '—',
                'teams' => $p->team_count ?? '—',
                'archived' => $off < 0,   // 過去の案件はピックアップ対象から外す
                'draft' => false,
                'entrants' => $entrants,
                'members' => $memberNames,
            ];
        })->values();
    }

    /** 実施形態の文字列 → コード（real/long/online）。cases.js の fmt と同じ。 */
    private function fmtCode(?string $format): string
    {
        $f = (string) $format;
        if (mb_strpos($f, 'オンライン') !== false) {
            return 'online';
        }
        if (mb_strpos($f, 'ロング') !== false) {
            return 'long';
        }
        if (mb_strpos($f, 'リアル') !== false) {
            return 'real';
        }

        return '';
    }

    /** 指定IDの people をポジション可否込みで引き、id でキー付けして返す。 */
    private function peopleWithPos(array $ids): Collection
    {
        return Person::whereIn('id', $ids)
            ->with('roleEligibilities:staff_id,position')
            ->get()
            ->keyBy('id');
    }

    /** 案件のDB状態 → ボードの4状態（todo/adj/fix/pub）。 */
    private function boardState(Project $p): string
    {
        if ($p->staff_published) {
            return 'pub';
        }
        if ($p->status === '確定') {
            return 'fix';
        }
        if ($p->status === '未着手' || $p->status === null) {
            return 'todo';
        }

        return 'adj';
    }

    /** 区分（新人/中堅/ベテラン） → 画面のキー（new/mid/vet）。不明は mid。 */
    private function lvCode(?string $skillLevel): string
    {
        return match ($skillLevel) {
            'ベテラン' => 'vet',
            '新人' => 'new',
            default => 'mid',   // 中堅・null（入社日未登録）
        };
    }

    /**
     * その人の主ポジションを画面表示用ラベルで返す。
     * できる役割のうち優先順（D→…→CK）で最上位のものを「主ポジション」とする。
     * （positions はDB上アルファベット順で入っているため、単純な先頭だと全員CKになってしまう）。無ければ 'FC'。
     */
    private function primaryPos(?Person $person): string
    {
        if (! $person) {
            return 'FC';
        }
        $have = $person->roleEligibilities->pluck('position')->all();
        if (empty($have)) {
            return 'FC';
        }
        foreach (self::POS_PRIORITY as $key) {
            if (in_array($key, $have, true)) {
                return self::POS_LABELS[$key] ?? $key;
            }
        }

        return self::POS_LABELS[$have[0]] ?? $have[0];
    }

    /** 開催日が今日から何日後か（過去はマイナス）。タイムゾーンに左右されない日数差。 */
    private function offDays(Carbon $start, Carbon $today): int
    {
        return intdiv(
            $start->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp,
            86400
        );
    }
}
