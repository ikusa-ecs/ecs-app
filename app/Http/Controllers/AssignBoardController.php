<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use App\Support\AssignmentRole;
use App\Support\AssignmentStamp;
use App\Support\OfficeScope;
use App\Support\ProjectAccess;
use App\Support\ShiftWish;
use App\Support\RecruitStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
    /** カレンダー表示（📅 空いている人）で先まで見せる日数。3か月ぶん。 */
    private const WISH_CALENDAR_DAYS = 92;

    /** 役割キー → ボード表示用のポジション名（assignments.role の値に対応）。 */
    private const POS_LABELS = [
        'D' => 'D', 'SD' => 'SD', 'MC' => 'MC', 'OP' => 'OP',
        'FC' => 'FC', 'CK' => 'CK', 'RP' => '受付', 'SP' => '軍師・サポーター',
    ];

    /** 主ポジションを選ぶ優先順（重要・経験者向けの役割を上に）。 */
    private const POS_PRIORITY = ['D', 'SD', 'MC', 'OP', 'SP', 'FC', 'RP', 'CK'];

    /** アサインボード（日別）/assign。案件＋割当メンバー＋希望者・稼働可・今月件数を DB から渡す。
     *  ?from=YYYY-MM-DD が来たら、その日を基準（先頭）に 3週間分を表示する（既定＝今日）。 */
    /**
     * 稼働可/希望の一覧を作った結果の覚え書き（同じ問い合わせを2回しないため）。
     *
     * @var array<string, array{byDay: array<int, mixed>, hidden: array<int, int>}>
     */
    private array $availCache = [];

    public function assign(Request $request)
    {
        $anchor = $this->boardAnchor($request);
        $office = OfficeScope::filter($request);

        return view('assign', [
            'staffPool' => $this->staffPool($office),
            // 「名簿から追加…」に出す人（社員＋スタッフ）。DBが元＝架空の名前を出さない。
            'roster' => $this->rosterPeople($office),
            'boardCases' => $this->boardCases($anchor, $office),
            'boardAvail' => $this->boardAvail($anchor, $office), // off → その日に稼働可/希望のスタッフ一覧
            // 拠点がちがうので出していない人数（off → 人数）。
            // ⚠ 黙って消すと「終日〇を出したのに希望者に出てこない」になるので、理由を画面に出す。
            'boardAvailHidden' => $this->boardAvailHidden($anchor, $office),
            'boardMonth' => $this->boardMonthCount($anchor),  // 名前 → ボード期間のアサイン件数（上限バッジ用）
            'anchor' => $anchor->format('Y-m-d'),             // 画面の基準日（日付ピッカーの初期値・日付計算の起点）
            'roleOptions' => AssignmentRole::positionLabels(), // ポジション編集プルダウンの選択肢（正本）
            'noteOptions' => $this->allNoteOptions(),          // 担当メモ入力の候補（軍師/サポ 等）
            'officeScope' => $office,                          // 今絞っている拠点（null＝全拠点）。注記に使う
            'usingDb' => Project::exists(),                    // DBに案件があるか（絞って0件でも見本に戻さない旗）
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
    public function entries(Request $request)
    {
        $office = OfficeScope::filter($request);

        return view('entries', [
            'staffPool' => $this->staffPool($office),
            'entriesCases' => $this->entriesCases($office),
            // カレンダー表示（📅 空いている人）用＝日付ごとの「終日〇を出している人」。
            'wishCalendar' => $this->wishCalendar($office),
            'wishCalendarDays' => self::WISH_CALENDAR_DAYS,
            'officeScope' => $office,
            'usingDb' => Project::exists(),
        ]);
    }

    /**
     * エントリー一覧の「📅 空いている人」カレンダー用（2026-09-03 baba要望
     * 「カレンダーを見れば終日〇の人の名前が載っている、が理想」）。
     *
     * 返り値＝ 'Y-m-d' => [ ['id','name','lv','pos','st'], ... ]
     *   st ＝ 'asg'（その日すでにアサイン済み）／'ent'（エントリー済み・まだアサインされていない）／''（まだ何も）
     *
     * ⚠ 出す人の決まりは日別ボードの希望者カラムと同じにそろえる。ずれると
     *   「ボードには出るのにカレンダーには出ない」で混乱する。
     *   ・スタッフだけ（社員はふだんイベントに出ないので出さない）
     *   ・退職・無効にした人は出さない
     *   ・拠点で絞って見ているときは自拠点だけ（事務所が未設定の人は東京で見たときに出る）
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function wishCalendar(?string $office = null): array
    {
        $from = Carbon::today();
        $to = $from->copy()->addDays(self::WISH_CALENDAR_DAYS);

        $byDay = ShiftWish::okStaffByDay($from->format('Y-m-d'), $to->format('Y-m-d'));
        if (! $byDay) {
            return [];
        }

        $ids = collect($byDay)->flatten()->unique()->values()->all();

        // 出してよい人（スタッフ・在籍中・拠点）。ここで一度に絞る。
        $allowed = OfficeScope::applyToPeople(Person::staff(), $office)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('active', true)->orWhereNull('active'))
            ->with('roleEligibilities:staff_id,position')
            ->get()
            ->keyBy('id');

        if ($allowed->isEmpty()) {
            return [];
        }

        // その日すでにアサインされている人（キャンセル以外）。
        $asg = Assignment::whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id', 'date'])
            ->groupBy(fn ($a) => Carbon::parse($a->date)->format('Y-m-d'))
            ->map(fn ($rows) => array_flip($rows->pluck('staff_id')->all()))
            ->all();

        // その日の案件にエントリー（応募）している人。
        $projectDay = Project::whereNotNull('start_date')
            ->whereBetween('start_date', [$from->format('Y-m-d'), $to->format('Y-m-d').' 23:59:59'])
            ->notCancelled()
            ->pluck('start_date', 'id')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->all();

        $ent = [];
        if ($projectDay) {
            foreach (Application::whereIn('project_id', array_keys($projectDay))->get(['project_id', 'staff_id']) as $a) {
                $ent[$projectDay[$a->project_id]][$a->staff_id] = true;
            }
        }

        $out = [];
        foreach ($byDay as $day => $staffIds) {
            foreach (array_unique($staffIds) as $sid) {
                $person = $allowed->get($sid);
                if (! $person) {
                    continue;   // 社員・退職者・他拠点
                }
                $out[$day][] = [
                    'id' => $sid,
                    'name' => $person->name ?? $sid,
                    'lv' => $this->lvCode($person->skill_level),
                    'pos' => $this->primaryPos($person),
                    'st' => isset($asg[$day][$sid]) ? 'asg' : (isset($ent[$day][$sid]) ? 'ent' : ''),
                ];
            }
            if (isset($out[$day])) {
                // まだ何も決まっていない人を先に＝声を掛けられる人が上に来る。
                usort($out[$day], fn ($a, $b) => [$a['st'] === '' ? 0 : 1, $a['name']] <=> [$b['st'] === '' ? 0 : 1, $b['name']]);
            }
        }

        return $out;
    }

    /** ピックアップ /pickup。案件＋候補者（応募＋当日稼働可）＋現メンバーを DB から渡す。 */
    public function pickup(Request $request)
    {
        $office = OfficeScope::filter($request);

        return view('pickup', [
            'staffPool' => $this->staffPool($office),
            'pickupCases' => $this->pickupCases($office),
            'roleOptions' => AssignmentRole::positionLabels(),   // 担当役割プルダウンの選択肢（正本）
            'noteOptions' => $this->allNoteOptions(),            // 担当メモ入力の候補（軍師/サポ 等）
            'officeScope' => $office,
            'usingDb' => Project::exists(),
        ]);
    }

    /**
     * ピックアップのメンバーを DB（assignments）へ保存する。
     * 「いま画面にいるメンバー」で、その案件×開催日 を上書きする（外した人は削除）。
     * project-assign の save() と同じ“上書き”の考え方。担当メモ・巡回数も一緒に保存する。
     */
    public function pickupSave(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string'],
            'members' => ['nullable', 'array'],
            'members.*.staff_id' => ['required', 'string'],
            'members.*.role' => ['nullable', 'string'],
            'members.*.role2' => ['nullable', 'string'],
            'members.*.note' => ['nullable', 'string'],
            'members.*.patrol' => ['nullable'],
            'members.*.remark' => ['nullable', 'string'],
            'members.*.status' => ['nullable', 'in:仮,確定'],
        ]);

        $project = Project::find($data['project_id']);
        if (! $project) {
            return response()->json(['ok' => false, 'message' => '案件が見つかりません。'], 404);
        }
        // 拠点チェック（保存の入口で必ず通す）＝他拠点の案件をURL直打ちで書き換えられないようにする。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }
        if (! $project->start_date) {
            return response()->json(['ok' => false, 'message' => 'この案件は開催日が未設定です。先に案件登録で日付を入れてください。'], 422);
        }

        $date = $project->start_date->format('Y-m-d');
        $members = $data['members'] ?? [];
        $memberIds = collect($members)->pluck('staff_id')->filter()->unique()->values()->all();

        DB::transaction(function () use ($project, $date, $members, $memberIds) {
            // 日付部分だけで照合して消す（date キャストの時刻付き保存対策。save()と同じ）。
            //
            // ⚠ 消すのは「この画面が扱う人」の行だけにする（手動アサイン画面と同じ案A・2026-08-06 確定）。
            //    以前はその案件×その日を無条件で全部消していたため、D決め画面で決めた社員の行
            //    （D/SD/FC）まで消えて「ピックアップを保存したらDが消える」事故になっていた。
            //    ＝スタッフの行（＋今回送られてきた人の行）だけを消してから作り直す。
            Assignment::where('project_id', $project->id)
                ->whereDate('date', $date)
                ->where(function ($q) use ($memberIds) {
                    $q->whereIn('staff_id', Person::staff()->select('id'));
                    if ($memberIds) {
                        $q->orWhereIn('staff_id', $memberIds);
                    }
                })
                ->delete();

            $seen = [];
            foreach ($members as $m) {
                $sid = $m['staff_id'];
                if (isset($seen[$sid])) {
                    continue;   // 同じ人の重複は1回だけ
                }
                $seen[$sid] = true;

                $note = trim((string) ($m['note'] ?? ''));
                $patrolRaw = $m['patrol'] ?? null;
                $remark = trim((string) ($m['remark'] ?? ''));
                $status = ($m['status'] ?? '仮') === '確定' ? '確定' : '仮';
                Assignment::create([
                    'project_id' => $project->id,
                    'staff_id' => $sid,
                    'date' => $date,
                    'role' => AssignmentRole::isValid($m['role'] ?? null) ? $m['role'] : '',
                    'role2' => AssignmentRole::isValid($m['role2'] ?? null) ? $m['role2'] : null,
                    'note' => $note === '' ? null : mb_substr($note, 0, 100),
                    'patrol' => (is_numeric($patrolRaw) && (int) $patrolRaw >= 0) ? (int) $patrolRaw : null,
                    'remark' => $remark === '' ? null : mb_substr($remark, 0, 200),
                    'status' => $status,
                ] + AssignmentStamp::forCreate($status));
            }
        });

        return response()->json(['ok' => true, 'count' => count($members), 'date' => $date]);
    }

    /**
     * スタッフ名の一覧（経験回数の多い順）。NAME_POOL の単一ソース。
     * 拠点で絞るときは自拠点のスタッフだけ（$office が null なら全拠点）。
     *
     * @return list<string>
     */
    private function staffPool(?string $office = null): array
    {
        return OfficeScope::applyToPeople(Person::staff(), $office)
            ->orderByDesc('experience_count')
            ->pluck('name')
            ->all();
    }

    /**
     * 日別ボードの「名簿から追加…」プルダウンに出す人（社員＋スタッフ）。
     *
     * ⚠ 以前ここは凍結モック /ecs/data/people.js の ECS_PEOPLE をそのまま並べていた
     *   （2026-08-24 に発見）。画面には架空の名前が出るのに、選ぶと「その架空の人のID」で
     *   本物のアサインが保存される。ECSが自動で振る社員番号も E-001 形式なので、
     *   実在する別人のアサインが作られる＝人の取り違えが起きる状態だった。
     *
     * 並びは五十音順（ふりがな）。拠点で絞っているときはその拠点の人だけ。
     *
     * @return list<array<string, mixed>>
     */
    private function rosterPeople(?string $office = null): array
    {
        // 区分（新人/中堅/ベテラン）は画面のバッジ用のコードに直す。
        $lvCode = ['新人' => 'new', '中堅' => 'mid', 'ベテラン' => 'vet'];

        return OfficeScope::applyToPeople(Person::query(), $office)
            ->where('active', true)   // 退職した人は候補に出さない
            ->byKana()
            ->with('roleEligibilities')
            ->get()
            ->map(function (Person $p) use ($lvCode) {
                // できるポジション → {D:true, OP:false, ...}（スタッフ名簿と同じ形）
                $can = $p->roleEligibilities->pluck('position')->all();
                $pos = [];
                foreach (AssignmentRole::POSITIONS as $k) {
                    $pos[$k] = in_array($k, $can, true);
                }

                $lv = $lvCode[$p->skill_level ?? ''] ?? '';

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'role' => $p->role,                 // employee / staff
                    'lv' => $lv,                        // new / mid / vet（空＝入社日未入力）
                    'lvLabel' => $p->skill_level ?? '—',
                    'pos' => $pos,
                    // Dの経験があるコンテンツ（社員のとき D か FC かの既定を決めるのに使う）
                    'dexp' => $p->director_contents ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * ボード用の案件データ（割当メンバー込み）を DB から組み立てる。
     * Blade の前処理済みの形（id/off/name/.../assigned）に合わせて返す。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function boardCases(Carbon $anchor, ?string $office = null): Collection
    {
        // ボード対象＝完了/下書きでなく、開催日があり、基準日〜21日先の案件。
        // 拠点で絞るときは「登録拠点がその拠点」＋「その拠点に共有された案件」（案件一覧と同じ）。
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->notCancelled()   // キャンセルになった案件は並べない（2026-08-26）
            ->orderBy('start_date')
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
            ->get(['project_id', 'staff_id', 'role', 'role2', 'status', 'note', 'patrol', 'remark']);

        // この案件群への応募（applications）＝希望者カラムの元。note＝本人が応募時に書いた一言。
        $apps = Application::whereIn('project_id', $projectIds)->get(['project_id', 'staff_id', 'note']);

        // 関係する人（名前・区分・できる役割）をまとめて引く。
        $people = $this->peopleWithPos(
            $assignments->pluck('staff_id')->merge($apps->pluck('staff_id'))->unique()->all()
        );

        $assignedByProject = $assignments->groupBy('project_id');
        $appsByProject = $apps->groupBy('project_id');

        // コンテンツ未登録の判定用＝マスタに存在するコンテンツID一覧。
        $validContentIds = Content::pluck('id')->all();

        return $projects->map(function (array $pair) use ($assignedByProject, $appsByProject, $people, $validContentIds) {
            [$p, $off] = $pair;

            // ひもづくコンテンツがマスタに1つも無い＝「コンテンツ未登録」（案件名で代用表示＋印を出す）。
            $cids = is_array($p->content_ids) ? $p->content_ids : [];
            $contentMissing = empty($cids) || empty(array_intersect($cids, $validContentIds));

            // 割当メンバー（assignments → 表示用 {name, lv, pos, type}）。
            $assigned = ($assignedByProject->get($p->id) ?? collect())
                ->map(function ($a) use ($people) {
                    $person = $people->get($a->staff_id);

                    return [
                        'name' => $person->name ?? $a->staff_id,
                        'id' => $a->staff_id,          // ポジション編集の保存に使う（案件×人）
                        'lv' => '-',   // 経験レベルは希望者カラム用。メンバー行では未表示。
                        'pos' => self::POS_LABELS[$a->role] ?? ($a->role ?: '—'),
                        'roleCode' => $a->role ?: '',  // 保存用の役割コード（プルダウンの初期選択）
                        'roleCode2' => $a->role2 ?: '', // 兼任（サブ役割）
                        'note' => $a->note ?? '',      // 担当メモ（軍師/サポ 等）
                        'patrol' => $a->patrol,        // 巡回数（数値／null）
                        'remark' => $a->remark ?? '',  // 備考（一言・自由記入）
                        'status' => $a->status,        // 仮/確定（保存時に維持する）
                        'type' => ($person && $person->role === 'employee') ? 'emp' : 'staff',
                    ];
                })
                ->values()
                ->all();

            // 応募者（applications → 希望者カラム用 {name, lv, pos}）。cal は画面側で当日の稼働可と突合。
            $applicants = ($appsByProject->get($p->id) ?? collect())
                ->unique('staff_id')->values()
                ->map(function ($a) use ($people) {
                    $sid = $a->staff_id;
                    $person = $people->get($sid);

                    return [
                        'id' => $sid,                                   // DB保存に使う（希望者→メンバー化）
                        'name' => $person->name ?? $sid,
                        'lv' => $this->lvCode(optional($person)->skill_level),
                        'pos' => $this->primaryPos($person),
                        'roleCode' => $this->primaryPosCode($person),   // 担当役割の初期値
                        // 社員かどうか（2026-09-03 baba要望）。
                        // ⚠ 社員は「基本イベントには出ない」ので、希望者カラムでは**たたんで**出す。
                        //   スタッフと混ざって並ぶと、声を掛ける相手を探すのに邪魔になる。
                        'emp' => (optional($person)->role === 'employee'),
                        // 本人が応募時に書いた一言。アサインする人に見えないと意味がないので渡す（2026-08-21 baba）。
                        'note' => (string) ($a->note ?? ''),
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

            // 「自分の案件」印＝営業担当かディレクターが自分かどうか。
            // ⚠ 以前は in_array('baba', ...) と名前を直書きしていたため、誰がログインしても
            //   baba さんの案件だけに印が付いていた（2026-08-24 修正）。
            $myName = trim((string) (Auth::user()->name ?? ''));
            $mine = $myName !== ''
                && (in_array($myName, $salesOwners, true) || ($p->director_id ?? '') === (Auth::id() ?? '~'));

            return [
                'id' => $p->id,
                'off' => $off,
                'name' => $p->project_name,
                'contentMissing' => $contentMissing,
                'client' => $p->client ?? '',
                'note' => $p->note ?? '',   // 案件の備考（見落とし防止でカードに出す）
                'cat' => $p->site_category ?: '通常',
                'need' => $p->required_count ?? 0,
                'filled' => count($assigned),
                'state' => $state,
                // ⚠ ボタンの出し分けはこの2つで行う（state ではなく）。
                //   stat＝案件の進み具合だけ／pubOn＝スタッフに公開して募集中かどうか。
                //   別のことなので別々に持つ（2026-08-28 baba指摘）。
                'stat' => $this->boardStatus($p),
                'pubOn' => (bool) $p->staff_published,
                // スタッフ募集を続けているか。⚠ 「公開しているか」とは別。
                //   確定にすると締まる（正本＝RecruitStatus::closeOnConfirmed）。
                //   これを渡していなかったので、募集を止めた案件でも「募集中 あと◯名」と出ていた。
                'recruit' => (bool) $p->is_recruiting,
                // 確度（Aヨミ/Bヨミ/Cヨミ）。⚠ 確度が低い案件は無くなることがあるので、
                //   アサインを詰める前に気づけるようカードにも出す（2026-08-28 baba要望）。
                'yomi' => (string) ($p->yomi ?? ''),
                // 実施形態（リアル／リアルロング／オンライン 等）。2026-09-01 baba要望。
                // アサインを詰めるときに、現地かオンラインかで声を掛ける人が変わるため。
                // ⚠ バッジの色分けはサーバー側で決める。正本＝App\Support\ProjectFormats::badgeCode
                //   （画面ごとに判定を書くと、片方だけ直して食い違う）。
                'format' => (string) ($p->format ?? ''),
                'fmtCls' => \App\Support\ProjectFormats::badgeCode($p->format),
                // ⚠ スタッフの画面で使っている必要人数（未入力なら既定の人数）。正本＝RecruitStatus。
                //   これで「締切（満員）／募集中」を判定する＝社員とスタッフで言うことを合わせる。
                //   運営人数を増やせば、その場でまた「募集中」に戻る（公開し直さなくてよい）。
                'needStaff' => RecruitStatus::need($p->required_count),
                'mine' => $mine,
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
     * ⚠ 出さない人が2種類ある（2026-09-03）。どちらも**黙って消える**と
     *   「終日〇を出したのに希望者に出てこない」になるので、
     *   拠点ちがいのぶんだけは人数を数えて画面に伝える（boardAvailHidden）。
     *   ・退職・無効にした人 …… 出さない（baba決定 2026-09-03）
     *   ・拠点がちがう人 …… 出さない（拠点ごとに分けて見る方針のまま・baba決定 2026-09-03）
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private function boardAvail(Carbon $anchor, ?string $office = null): array
    {
        return $this->boardAvailAll($anchor, $office)['byDay'];
    }

    /**
     * 「拠点がちがうので出していない人」の日ごとの人数。
     * ⚠ これを画面に出さないと、なぜ出てこないのか誰にも分からない。
     *
     * @return array<int, int>
     */
    private function boardAvailHidden(Carbon $anchor, ?string $office = null): array
    {
        return $this->boardAvailAll($anchor, $office)['hidden'];
    }

    /**
     * 稼働可/希望の一覧と、拠点ちがいで隠した人数を、いっぺんに作る。
     * ⚠ 同じ問い合わせを2回しないよう、1回だけ作って覚えておく。
     *
     * @return array{byDay: array<int, array<int, array<string, mixed>>>, hidden: array<int, int>}
     */
    private function boardAvailAll(Carbon $anchor, ?string $office = null): array
    {
        $key = $anchor->format('Y-m-d').'|'.($office ?? '');
        if (isset($this->availCache[$key])) {
            return $this->availCache[$key];
        }

        $end = $anchor->copy()->addDays(21);

        $prefs = ShiftPreference::whereBetween('date', [$anchor->format('Y-m-d'), $end->format('Y-m-d')])
            ->whereIn('availability', ['稼働可', '希望'])
            ->get(['staff_id', 'date']);

        if ($prefs->isEmpty()) {
            return $this->availCache[$key] = ['byDay' => [], 'hidden' => []];
        }

        $people = $this->peopleWithPos($prefs->pluck('staff_id')->unique()->all());

        // 拠点で絞るときに「出してよい人」の一覧。
        // ⚠ 社員もスタッフも同じ決まりで絞る。以前はスタッフだけを対象にしていたため、
        //   拠点で絞って見ると社員が丸ごと消え、全拠点で見ると出る＝見る人で違う状態だった。
        $allowed = $office
            ? array_flip(OfficeScope::applyToPeople(Person::query(), $office)->pluck('id')->all())
            : null;

        $out = [];
        $hidden = [];
        foreach ($prefs as $pref) {
            $off = $this->offDays($pref->date, $anchor);
            $person = $people->get($pref->staff_id);

            // 退職・無効にした人は候補に出さない（2026-09-03 baba決定）。
            // 「＋スタッフを追加」の一覧では前から除かれているので、そちらに合わせる。
            if ($person && $person->active === false) {
                continue;
            }

            if ($allowed !== null && ! isset($allowed[$pref->staff_id])) {
                $hidden[$off] = ($hidden[$off] ?? 0) + 1;
                continue;
            }

            $out[$off][] = [
                'id' => $pref->staff_id,                        // DB保存に使う（希望者→メンバー化）
                'name' => $person->name ?? $pref->staff_id,
                'lv' => $this->lvCode(optional($person)->skill_level),
                'pos' => $this->primaryPos($person),
                'roleCode' => $this->primaryPosCode($person),   // 担当役割の初期値
                // 社員かどうか（2026-09-03 baba要望）。⚠ 社員の出勤可能日もこの同じ表
                //   （shift_preferences）に入るので、印を付けないとスタッフと見分けられない。
                'emp' => (optional($person)->role === 'employee'),
            ];
        }

        return $this->availCache[$key] = ['byDay' => $out, 'hidden' => $hidden];
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
    private function entriesCases(?string $office = null): Collection
    {
        $today = Carbon::today();

        // 拠点で絞るのは「案件」だけ。応募者（applications）は本人が手を挙げた記録なので、
        // 他拠点のスタッフが応募していてもそのまま出す（隠すと応募が無かったように見えてしまう）。
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->notCancelled()   // キャンセルになった案件は並べない（2026-08-26）
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true));

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->pluck('id');

        // 応募（applications）＝この案件に「エントリーする」を出した人。note＝本人が応募時に書いた一言。
        $apps = Application::whereIn('project_id', $projectIds)->get(['project_id', 'staff_id', 'note']);
        // 確定/仮アサイン（assignments・キャンセル除く）＝アサイン済みかの判定に使う。status・remark(担当メモ)も取る。
        $assignedRows = Assignment::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'status', 'remark']);

        $people = $this->peopleWithPos(
            $apps->pluck('staff_id')->merge($assignedRows->pluck('staff_id'))->unique()->all()
        );

        // その人が、その案件の日に「終日〇」を出しているか（2026-09-03 baba要望）。
        // ⚠ エントリー（応募）と稼働希望カレンダーは**別の入力**。両方見ないと
        //   「手は挙げてくれたが、その日はNGにしている」人に気づけない。
        //   出し方の正本＝App\Support\ShiftWish（エントリー新着 /entry-feed と同じもの）。
        $wishByKey = ShiftWish::forDays(
            $apps->pluck('staff_id')->unique()->all(),
            $projects->pluck('start_date')->filter()->map(fn ($d) => $d->format('Y-m-d'))->all()
        );

        $appsByProject = $apps->groupBy('project_id');
        // 案件×人 → 本人の応募メモ（applications.note）。
        $noteByProject = $apps->groupBy('project_id')->map(fn ($rows) => $rows->pluck('note', 'staff_id')->all());
        $assignedByProject = $assignedRows->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->all());
        // 案件×人 → アサイン状態（'確定'/'仮'）。同じ人に複数行あれば確定を優先。
        $statusByProject = $assignedRows->groupBy('project_id')->map(function ($rows) {
            $map = [];
            foreach ($rows as $r) {
                if (! isset($map[$r->staff_id]) || $r->status === '確定') {
                    $map[$r->staff_id] = $r->status;
                }
            }

            return $map;
        });
        // 案件×人 → 担当メモ（assignments.remark）。アサイン画面等と同じ備考＝同期して表示・編集する。
        $remarkByProject = $assignedRows->groupBy('project_id')->map(function ($rows) {
            $map = [];
            foreach ($rows as $r) {
                if ($r->remark !== null && $r->remark !== '') {
                    $map[$r->staff_id] = $r->remark;
                }
            }

            return $map;
        });

        return $projects->map(function (Project $p) use ($today, $appsByProject, $assignedByProject, $statusByProject, $noteByProject, $remarkByProject, $people, $wishByKey) {
            $assignedIds = $assignedByProject->get($p->id, []);
            $assignedStatus = $statusByProject->get($p->id, []);   // [staff_id => '確定'|'仮']
            $entryNotes = $noteByProject->get($p->id, []);         // [staff_id => 本人の応募メモ]
            $remarks = $remarkByProject->get($p->id, []);          // [staff_id => 担当メモ(remark)]
            $off = $this->offDays($p->start_date ?? $today, $today);

            // 応募者リスト（applications → 表示用 {no, name, lv, pos, assigned, status, entryNote, remark}）。
            $entrants = ($appsByProject->get($p->id) ?? collect())
                ->pluck('staff_id')->unique()->values()
                ->map(function ($sid, $i) use ($people, $assignedIds, $assignedStatus, $entryNotes, $remarks, $wishByKey, $p) {
                    $person = $people->get($sid);

                    return [
                        'no' => $i + 1,
                        // その日の稼働希望（'ok'＝終日〇／'ng'＝NG・希望休／null＝出していない）。
                        'wish' => ShiftWish::of($wishByKey, $sid, $p->start_date?->format('Y-m-d')),
                        'id' => $sid,                              // スタッフID（エントリー一覧からのアサイン保存に使う）
                        'name' => $person->name ?? $sid,
                        'lv' => $this->lvCode($person?->skill_level),
                        'pos' => $this->primaryPos($person),       // 表示用ラベル
                        'roleCode' => $this->primaryPosCode($person), // 保存用の役割コード（D/OP/…）
                        'assigned' => in_array($sid, $assignedIds, true),
                        'status' => $assignedStatus[$sid] ?? null,   // '確定'/'仮'/null（未アサイン）
                        'entryNote' => $entryNotes[$sid] ?? '',      // 本人が応募時に書いた一言（読むだけ）
                        'remark' => $remarks[$sid] ?? '',            // 担当メモ＝アサインの備考(remark)と同期
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
    private function pickupCases(?string $office = null): Collection
    {
        $today = Carbon::today();

        // 拠点で絞るのは「案件」だけ。候補者＝応募者∪現メンバー＝その案件に紐づく人なので、
        // 他拠点の人でもそのまま出す（メンバーが消えると保存＝上書きで担当が外れてしまう）。
        $projects = OfficeScope::applyToProjects(Project::with('director:id,name'), $office)
            ->notCancelled()   // キャンセルになった案件は並べない（2026-08-26）
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true));

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->pluck('id');

        // ⚠ note＝本人がエントリーのときに書いた一言。人を選ぶ画面なのに届いていなかった
        //   （2026-08-28 baba指摘。/assign や /entries には出ていたが、この画面だけ抜けていた）。
        $apps = Application::whereIn('project_id', $projectIds)->get(['project_id', 'staff_id', 'note']);
        $assignedRows = Assignment::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'role', 'role2', 'status', 'note', 'patrol', 'remark']);

        $staffIds = $apps->pluck('staff_id')->merge($assignedRows->pluck('staff_id'))->unique();
        $people = $this->peopleWithPos($staffIds->all());

        // 当日稼働可（shift_preferences の 稼働可/希望）を (staff_id|Y-m-d) のセットにしておく。
        $availSet = [];
        foreach (ShiftPreference::whereIn('staff_id', $staffIds->all())
            ->whereIn('availability', ['稼働可', '希望'])
            ->get(['staff_id', 'date']) as $pref) {
            $availSet[$pref->staff_id.'|'.$pref->date->format('Y-m-d')] = true;
        }

        $appsByProject = $apps->groupBy('project_id')->map(fn ($r) => $r->pluck('staff_id')->all());
        // 案件 → 人 → その人が書いた一言（エントリーのコメント）。
        $entryNoteByProject = $apps->groupBy('project_id')->map(fn ($r) => $r->pluck('note', 'staff_id')->all());
        $assignedByProject = $assignedRows->groupBy('project_id')
            ->map(fn ($r) => $r->pluck('staff_id')->unique()->all());
        // 案件×人 → 割当の詳細（役割・担当メモ・巡回・状態）。メンバー行の初期値に使う。
        $assignInfoByProject = $assignedRows->groupBy('project_id')->map(function ($rows) {
            $map = [];
            foreach ($rows as $r) {
                $map[$r->staff_id] = [
                    'roleCode' => $r->role ?: '',
                    'roleCode2' => $r->role2 ?: '',
                    'note' => $r->note ?? '',
                    'patrol' => $r->patrol,
                    'remark' => $r->remark ?? '',
                    'status' => $r->status,
                ];
            }

            return $map;
        });

        $contentNames = Content::pluck('content_name', 'id');

        return $projects->map(function (Project $p) use ($today, $appsByProject, $entryNoteByProject, $assignedByProject, $assignInfoByProject, $availSet, $people, $contentNames) {
            $assignedIds = $assignedByProject->get($p->id, []);
            $applicantIds = $appsByProject->get($p->id, []);
            $entryNotes = $entryNoteByProject->get($p->id, []);
            $assignInfo = $assignInfoByProject->get($p->id, []);
            $off = $this->offDays($p->start_date ?? $today, $today);

            // 候補者プール＝応募者 ∪ 現メンバー（重複は除く）。
            $candIds = collect($applicantIds)->merge($assignedIds)->unique()->values();
            $dateStr = $p->start_date ? $p->start_date->format('Y-m-d') : null;

            $entrants = $candIds->map(function ($sid) use ($people, $availSet, $dateStr, $entryNotes) {
                $person = $people->get($sid);

                return [
                    'id' => $sid,                                  // 保存用（案件×人）
                    'name' => $person->name ?? $sid,
                    'pos' => $this->primaryPos($person),
                    'roleCode' => $this->primaryPosCode($person),  // 担当役割の初期値
                    'cal' => $dateStr ? isset($availSet[$sid.'|'.$dateStr]) : false,
                    // 本人がエントリーのときに書いた一言（アサインの判断材料）。
                    'note' => trim((string) ($entryNotes[$sid] ?? '')),
                ];
            })->all();

            // メンバー（assignments）＝rosterの初期値。名前だけでなく id・担当・巡回も持たせる（DB保存に使う）。
            $members = collect($assignedIds)
                ->map(function ($sid) use ($people, $assignInfo) {
                    $info = $assignInfo[$sid] ?? [];

                    return [
                        'id' => $sid,
                        'name' => optional($people->get($sid))->name ?? $sid,
                        'roleCode' => $info['roleCode'] ?? '',
                        'roleCode2' => $info['roleCode2'] ?? '',
                        'pos' => self::POS_LABELS[$info['roleCode'] ?? ''] ?? ($info['roleCode'] ?? ''),
                        'note' => $info['note'] ?? '',
                        'patrol' => $info['patrol'] ?? null,
                        'remark' => $info['remark'] ?? '',
                        'status' => $info['status'] ?? '仮',
                    ];
                })
                ->values()->all();

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId ? ($contentNames[$firstContentId] ?? $p->project_name) : $p->project_name;

            return [
                'id' => $p->id,
                'off' => $off,
                'date' => $dateStr,          // 保存に使う開催日（null＝日付未設定で保存不可）
                'content' => $content,
                'name' => $p->project_name,
                'client' => $p->client ?? '',
                'note' => $p->note ?? '',    // 案件の備考（見落とし防止でカードに出す）
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
                'members' => $members,
            ];
        })->values();
    }

    /**
     * 担当メモ入力の候補（datalist用・全体）＝必要人数リストで使われている備考の一覧。
     * 例：['軍師', 'サポ', '全体サポ']。ピックアップは案件をまたいで編集するため全体から候補を出す。
     *
     * @return list<string>
     */
    private function allNoteOptions(): array
    {
        return ContentRoleRequirement::whereNotNull('note')
            ->where('note', '!=', '')
            ->orderBy('sort_order')
            ->pluck('note')
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 実施形態の文字列 → コード（real/long/online）。メンバー決め画面の絞り込みに使う。
     *
     * ⚠ ここに判定を書かない。正本＝App\Support\ProjectFormats::countCode
     *   （public/ecs/data/cases.js の ECS_fmtCode と同じ規則）。
     * ⚠ 実施形態が未設定の案件は絞り込みから外す（空を返す）＝勝手に「リアル」と決めない。
     *   ARENA場所貸し・体験会は正本どおり「リアル」に入る（2026-08-31 修正。それまでは
     *   どの絞り込みを選んでも一覧から消えていた）。
     */
    private function fmtCode(?string $format): string
    {
        return trim((string) $format) === ''
            ? ''
            : \App\Support\ProjectFormats::countCode($format);
    }

    /** 指定IDの people をポジション可否込みで引き、id でキー付けして返す。 */
    private function peopleWithPos(array $ids): Collection
    {
        return Person::whereIn('id', $ids)
            ->with('roleEligibilities:staff_id,position')
            ->get()
            ->keyBy('id');
    }

    /**
     * 案件のDB状態 → ボードの4状態（todo/adj/fix/pub）。
     *
     * ⚠ 絞り込みと色分けのために残しているが、**これは2つのことを1つにまとめてしまっている**。
     *   ボタンの出し分けには使わないこと（下の boardStatus / staff_published を使う）。
     *   理由は boardStatus のコメント。
     */
    private function boardState(Project $p): string
    {
        if ($p->staff_published) {
            return 'pub';
        }

        return $this->boardStatus($p);
    }

    /**
     * 案件の進み具合だけ（todo＝未着手 / adj＝調整中 / fix＝確定）。公開は見ない。
     *
     * ⚠ 【なぜ分けるか（2026-08-28 baba指摘）】
     * 「スタッフに公開」は**募集をかける操作**で、メンバーのアサインとは別のこと。
     * ところが以前は「公開ずみなら pub」で他をぜんぶ隠していたため、
     * **募集をかけた瞬間に「✓ 確定にする」（＝メンバーを確定にする）ボタンが消えて**いた。
     * 実際の流れは 公開して募集 → エントリーが集まる → アサイン → **そこで確定**、
     * なので、いちばん必要なときにボタンが無い状態だった。
     * 公開（募集中かどうか）と、案件の進み具合は**別々に持つ**。
     */
    private function boardStatus(Project $p): string
    {
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

    /**
     * その人の主ポジションを「役割コード（D/OP/MC/…）」で返す。primaryPos の表示ラベルではなく
     * assignments.role にそのまま入れられるコードが欲しい場面（エントリー一覧からのアサイン保存）で使う。
     * できる役割が無ければ 'FC'。
     */
    private function primaryPosCode(?Person $person): string
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
                return $key;
            }
        }

        return $have[0];
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
