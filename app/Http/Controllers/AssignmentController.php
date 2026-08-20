<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use App\Support\AssignmentRole;
use App\Support\AssignmentScorer;
use App\Support\AssignmentStamp;
use App\Support\ProjectAccess;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 手動アサインのDB保存（A-2）。案件一覧 →「アサイン」→ この画面。
 *
 * 既存のモック（/assign 日別ボード・/assign-detail 案件詳細）とは別に、
 * 「本物の案件 × 本物のスタッフ（people）× 日付」を assignments テーブルへ保存する
 * “本物の道”を1本通す。ここが埋まると稼働状況・マイページ・スタッフ画面が
 * 同じ assignments を見て本物のデータでつながる。
 */
class AssignmentController extends Controller
{
    // 月間アサイン上限（過重労働防止・一律20件／設計書11章F）。超えても保存はできる＝警告のみ。
    private const MONTH_CAP = 20;

    // 「できる役割」バッジに出すコード＝D・MC・OP・軍師(SP/GUN)のみ（FC/CK/受付は全員できる等で非表示）。
    private const CAN_SHOWN = ['D', 'SD', 'MC', 'OP', 'SP', 'GUN'];

    // ※ 役割コード／ラベルの正本は App\Support\AssignmentRole（別表記を作らないため一本化）。

    /** アサイン画面（案件1件＋スタッフ名簿＋保存済みアサインを渡す）。 */
    public function show(Request $request)
    {
        $id = (string) $request->query('project', '');
        $project = Project::with('director:id,name')->find($id);
        if (! $project) {
            return redirect('/projects')
                ->with('status', 'アサインする案件が見つかりませんでした。案件一覧から選び直してください。');
        }

        // 見出しコンテンツ名（先頭）。無ければ案件名で代用。
        $firstContentId = is_array($project->content_ids) ? ($project->content_ids[0] ?? null) : null;
        $contentName = $firstContentId
            ? (Content::whereKey($firstContentId)->value('content_name') ?? $project->project_name)
            : $project->project_name;

        // この案件の日付（複数日案件は本番/予備日/リハで別レコード＝1案件1日）。
        $date = $project->start_date; // Carbon|null

        // すでに保存済みのアサイン（staff_id => ['role'=>, 'status'=>, 'note'=>, 'patrol'=>, 'remark'=>]）。再表示で選択済みにする。
        $existing = Assignment::where('project_id', $project->id)
            ->get()
            ->keyBy('staff_id')
            ->map(fn ($a) => [
                'role' => $a->role,
                'role2' => $a->role2 ?? '',      // 兼任（サブ役割）
                'status' => $a->status,
                'note' => $a->note ?? '',
                'patrol' => $a->patrol,
                'remark' => $a->remark ?? '',   // 人ごとの一言（自由記入）
            ])
            ->all();

        // この案件の日について、各スタッフが出した稼働希望（希望/稼働可/NG/未定）。
        // shift_preferences にまだ入力が無ければ空配列＝全員「—」表示（画面は従来どおり動く）。
        $wish = [];
        if ($date) {
            $wish = ShiftPreference::whereDate('date', $date->format('Y-m-d'))
                ->pluck('availability', 'staff_id')
                ->all();
        }

        // ── 警告用の集計（設計どおり「警告」であってハード制約にはしない）──
        // ① 同日・他案件にすでに割り当て済みの人（staff_id => [案件名,...]）＝ダブルブッキング検知
        $sameDay = [];
        // ② 今月のアサイン件数（staff_id => 件数）＝月20件上限の見える化
        $monthCount = [];
        // ※下の「頭脳（AssignmentScorer）」にも渡すので、スタッフ名簿を作る前に用意しておく。
        if ($date) {
            $ymd = $date->format('Y-m-d');

            $otherRows = Assignment::where('date', $ymd)
                ->where('project_id', '!=', $project->id)
                ->where('status', '!=', 'キャンセル')
                ->get(['project_id', 'staff_id']);
            $otherNames = Project::whereIn('id', $otherRows->pluck('project_id')->unique())
                ->pluck('project_name', 'id');
            foreach ($otherRows as $r) {
                $sameDay[$r->staff_id][] = $otherNames[$r->project_id] ?? $r->project_id;
            }

            $monthCount = Assignment::whereBetween('date', [
                $date->copy()->startOfMonth()->format('Y-m-d'),
                $date->copy()->endOfMonth()->format('Y-m-d'),
            ])
                ->where('status', '!=', 'キャンセル')
                ->selectRaw('staff_id, count(*) as c')
                ->groupBy('staff_id')
                ->pluck('c', 'staff_id')
                ->all();
        }

        // ── 自動アサインの「頭脳」に渡す材料（C-5）──
        // リピート継続：このクライアントの過去案件に出たスタッフ（is_repeat のとき加点）。
        $repeatStaffIds = [];
        if ($project->client) {
            $priorPids = Project::where('client', $project->client)
                ->where('id', '!=', $project->id)
                ->pluck('id');
            if ($priorPids->isNotEmpty()) {
                $repeatStaffIds = Assignment::whereIn('project_id', $priorPids)
                    ->where('status', '!=', 'キャンセル')
                    ->pluck('staff_id')
                    ->unique()
                    ->values()
                    ->all();
            }
        }
        // コンテンツ経験の一致判定に使う「この案件のコンテンツ名」。
        $contentIds = is_array($project->content_ids) ? array_filter($project->content_ids) : [];
        $contentNames = $contentIds
            ? Content::whereIn('id', $contentIds)->pluck('content_name')->all()
            : [];
        // NGペア同席の判定に使う「この案件にすでに入っている人の氏名」。
        $memberNames = Person::whereIn('id', array_keys($existing))->pluck('name')->all();

        $scorer = (new AssignmentScorer(
            $project, $date, $wish, $sameDay, $monthCount, $repeatStaffIds, $contentNames, self::MONTH_CAP
        ))->setProjectMemberNames($memberNames);

        // ── 候補スタッフを拠点で絞る（全拠点運用・2026-08-05 baba確定＝アサイン画面は第1弾）──
        // 管理者以上はスイッチで選んだ拠点（未選択＝全拠点）、一般社員は自拠点固定。null＝絞らない。
        // 理由＝ヘルプは「案件を自拠点に持ってきて自拠点のスタッフを入れる」運用なので、候補は自拠点で足りる。
        // ⚠ すでにこの案件へアサインされている人は、拠点が違っても必ず残す
        //    （保存は「チェックが入っている人で上書き」なので、候補から消えると保存時に外れてしまう）。
        $officeScope = OfficeScope::filter($request);
        $assignedIds = array_keys($existing);

        // スタッフ名簿。区分・できる役割・NG・この日の希望＋「おすすめ度（点数・理由）」も一緒に。
        // 並びは「おすすめ順（点数の高い順）」。NG該当（この日NG・NGペア同席）は末尾へ回す。
        $staff = OfficeScope::applyToPeople(Person::staff(), $officeScope, $assignedIds)
            ->with(['roleEligibilities', 'ngRelations'])
            ->orderByDesc('experience_count')
            ->get()
            ->map(function (Person $p) use ($wish, $scorer) {
                $can = $p->roleEligibilities->pluck('position')->all();
                // 「できる役割」バッジは D・MC・OP・軍師 だけ表示（FC・CK等は全員できるので出さない／baba 2026-07-17）。
                $canShown = array_values(array_filter($can, fn ($k) => in_array($k, self::CAN_SHOWN, true)));
                // OPはオンライン/リアルの区別（B案）を付ける。未設定は「OP（音響）」のまま。
                $posLabels = array_map(function ($k) use ($p) {
                    if ($k === 'OP') {
                        $fl = $this->opFlavor($p);
                        return $fl !== '' ? ('OP' . $fl) : AssignmentRole::label('OP');
                    }
                    return AssignmentRole::label($k);
                }, $canShown);
                $eval = $scorer->evaluate($p);

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'level' => $p->skill_level ?? '—',
                    'exp' => $p->experience_count ?? 0,
                    'exclusive' => (bool) $p->is_exclusive,
                    'posLabels' => $posLabels,
                    'posCodes' => $can,   // 自動仮置きで「この役割ができる人か」を判定するため
                    'ng' => $p->ngRelations->pluck('partner_name')->all(),
                    'wish' => $wish[$p->id] ?? null,
                    'score' => $eval['score'],
                    'reasons' => $eval['reasons'],
                    'warnings' => $eval['warnings'],
                    'blocked' => $eval['blocked'],
                    'blockReason' => $eval['blockReason'],
                ];
            })
            ->sortByDesc('score')
            ->sortBy(fn ($s) => $s['blocked'] ? 1 : 0)   // NG該当は末尾（PHP8の安定ソートで点数順は保たれる）
            ->values();

        // ── ポジション雛型（コンテンツ×規模 → 必要ポジション人数）──
        // マスタ（content_role_requirements）に人数が登録されていれば、D×1・MC×2… の「枠」を出す。
        // 未登録なら空配列＝画面は従来どおり（全スタッフ一覧＋役割セレクト）で使える。
        $roleReq = $this->positionTemplate($project);
        // 備考（担当）・巡回つきの内訳（必要アサイン人数リスト由来）。枠の下に見せる。
        $roleDetail = $this->positionDetail($project);
        // 担当メモ入力の候補（datalist用）＝このコンテンツ×規模で使われる備考の一覧（軍師／サポ 等）。
        $noteOptions = $this->noteOptions($project);

        // すでに役割つきで入っている人数を役割ごとに数える（枠の初期「現在◯」＝サーバ側の目安）。
        // 画面側でチェック／役割を動かすとJSで即時に上書きされる。
        $roleAssigned = [];
        foreach ($existing as $info) {
            $r = $info['role'] ?? '';
            if ($r !== '' && ($info['status'] ?? '') !== 'キャンセル') {
                $roleAssigned[$r] = ($roleAssigned[$r] ?? 0) + 1;
            }
        }

        return view('assignment', [
            'project' => $project,
            'contentName' => $contentName,
            'date' => $date,
            'staff' => $staff,
            'existing' => $existing,
            'roleLabels' => AssignmentRole::positionLabels(),
            'roleReq' => $roleReq,
            'roleDetail' => $roleDetail,
            // 枠が出せないとき（ポジション別人数が未登録）に見せる目標人数。
            // 案件の運営人数が未入力なら既定5名（スタッフ画面の表示と同じ考え方・2026-08-20 baba）。
            'needTotal' => ((int) ($project->required_count ?? 0)) > 0 ? (int) $project->required_count : 5,
            'needIsDefault' => ((int) ($project->required_count ?? 0)) <= 0,
            'noteOptions' => $noteOptions,
            'roleAssigned' => $roleAssigned,
            'sameDay' => $sameDay,
            'monthCount' => $monthCount,
            'monthCap' => self::MONTH_CAP,
            'officeScope' => $officeScope,   // 今絞っている拠点（null＝全拠点）。画面の注記に使う。
        ]);
    }

    /**
     * この案件の「必要ポジション人数（雛型）」を返す＝[役割コード => 人数]。
     *
     * 元データは content_role_requirements（コンテンツ×規模×ポジション×人数）。
     * 案件が複数コンテンツ（projects.content_ids は配列）なら、同じ規模の行を役割ごとに合計する。
     * コンテンツ未指定・規模未設定・人数未登録のときは空配列（＝枠を出さず従来どおり）。
     */
    private function positionTemplate(Project $project): array
    {
        $contentIds = is_array($project->content_ids) ? array_filter($project->content_ids) : [];
        $scale = $project->scale;
        if (empty($contentIds) || ! $scale) {
            return [];
        }

        $rows = ContentRoleRequirement::whereIn('content_id', $contentIds)
            ->where('scale', $scale)
            ->where('count', '>', 0)
            ->get(['position', 'count']);

        $sum = [];
        foreach ($rows as $r) {
            if (! AssignmentRole::isValid($r->position)) {
                continue; // 表記ゆれ・未知コードは無視（正本 AssignmentRole に寄せる）
            }
            $sum[$r->position] = ($sum[$r->position] ?? 0) + (int) $r->count;
        }

        // 表示順は AssignmentRole の定義順（D→SD→OP→…）にそろえる。
        $ordered = [];
        foreach (array_keys(AssignmentRole::LABELS) as $code) {
            if (! empty($sum[$code])) {
                $ordered[$code] = $sum[$code];
            }
        }

        return $ordered;
    }

    /**
     * 必要ポジションの「担当内訳」＝[役割コード => ['label'=>, 'items'=>[['note'=>,'patrol'=>,'count'=>], ...]]]。
     * content_role_requirements の note（備考）・patrol（巡回）を、役割×備考でまとめる。
     * 備考のあるものだけ返す（D/MC/OP のような指定なしの枠は「ポジション枠」の数字で足りるため）。
     */
    private function positionDetail(Project $project): array
    {
        $contentIds = is_array($project->content_ids) ? array_filter($project->content_ids) : [];
        $scale = $project->scale;
        if (empty($contentIds) || ! $scale) {
            return [];
        }

        $rows = ContentRoleRequirement::whereIn('content_id', $contentIds)
            ->where('scale', $scale)
            ->where('count', '>', 0)
            ->orderBy('sort_order')
            ->get(['position', 'count', 'note', 'patrol']);

        // 役割 → 「備考|巡回」キー → 件数 を集計。
        $agg = [];
        foreach ($rows as $r) {
            if (! AssignmentRole::isValid($r->position)) {
                continue;
            }
            $note = trim((string) ($r->note ?? ''));
            $patrol = $r->patrol;
            if ($note === '' && $patrol === null) {
                continue;   // 指定なしの枠は内訳に出さない（枠の数字で十分）
            }
            $key = $note . '|' . ($patrol ?? '');
            if (! isset($agg[$r->position][$key])) {
                $agg[$r->position][$key] = ['note' => $note, 'patrol' => $patrol, 'count' => 0];
            }
            $agg[$r->position][$key]['count'] += (int) $r->count;
        }

        // 表示順は AssignmentRole の定義順。
        $out = [];
        foreach (array_keys(AssignmentRole::LABELS) as $code) {
            if (empty($agg[$code])) {
                continue;
            }
            $out[] = [
                'label' => AssignmentRole::label($code),
                'items' => array_values($agg[$code]),
            ];
        }

        return $out;
    }

    /**
     * 担当メモ入力の候補（datalist用）＝このコンテンツ×規模で使われている備考の一覧。
     * 例：['軍師', 'サポ', '全体サポ']。自由入力もできるが、よく使う語をワンタップで選べるようにする。
     *
     * @return list<string>
     */
    private function noteOptions(Project $project): array
    {
        $contentIds = is_array($project->content_ids) ? array_filter($project->content_ids) : [];
        if (empty($contentIds) || ! $project->scale) {
            return [];
        }

        return ContentRoleRequirement::whereIn('content_id', $contentIds)
            ->where('scale', $project->scale)
            ->whereNotNull('note')
            ->where('note', '!=', '')
            ->orderBy('sort_order')
            ->pluck('note')
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** アサインを保存（いま選ばれている人で、その案件×その日を上書き）。 */
    public function save(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string'],
            'status' => ['required', 'in:仮,確定'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['string'],
            'role' => ['nullable', 'array'],
            'role2' => ['nullable', 'array'],         // 兼任＝サブ役割（staff_id => 役割コード）
            'note' => ['nullable', 'array'],          // 担当メモ（staff_id => 軍師/サポ 等）
            'patrol' => ['nullable', 'array'],         // 巡回数（staff_id => 数値）
            'remark' => ['nullable', 'array'],         // 備考（staff_id => 一言・自由記入）
        ]);

        $project = Project::find($data['project_id']);
        if (! $project) {
            return redirect('/projects')->with('status', 'アサインする案件が見つかりませんでした。');
        }
        // 拠点チェック（他拠点の案件をURL直打ちで書き換えられないようにする）。
        ProjectAccess::authorize($project);
        if (! $project->start_date) {
            return redirect('/project-assign?project=' . urlencode($project->id))
                ->with('status', '⚠ この案件は開催日が未設定です。先に案件登録で日付を入れてからアサインしてください。');
        }

        $date = $project->start_date->format('Y-m-d');
        $staffIds = $data['staff_ids'] ?? [];
        $roles = $data['role'] ?? [];
        $roles2 = $data['role2'] ?? [];
        $notes = $data['note'] ?? [];
        $patrols = $data['patrol'] ?? [];
        $remarks = $data['remark'] ?? [];
        $now = Carbon::now();

        // 「いま選ばれている人」で、その案件×その日 を上書き保存する（外した人は削除）。
        DB::transaction(function () use ($project, $date, $staffIds, $roles, $roles2, $notes, $patrols, $remarks, $data, $now) {
            // date は 'date' キャストで時刻付き保存になり得るため、日付部分だけで照合する
            // （quickToggle と同じ考え方。where('date',$date) だと空振りして再登録が unique 制約で 500 になる）。
            //
            // ⚠ 消すのは「この画面が扱う人」の行だけにする（案A・baba 2026-08-06 確定）。
            //    この画面の一覧に出るのはスタッフ（people.role='staff'）だけなので、
            //    社員の行（D/SD/FC＝D決め画面で決めたもの）まで消すと、チェックの付け直しで
            //    復活できず「アサインを保存したらDが消える」事故になっていた。
            //    ＝スタッフの行（＋今回送られてきた人の行）だけを消してから作り直す。
            Assignment::where('project_id', $project->id)
                ->whereDate('date', $date)
                ->where(function ($q) use ($staffIds) {
                    $q->whereIn('staff_id', Person::staff()->select('id'));
                    if ($staffIds) {
                        $q->orWhereIn('staff_id', $staffIds);
                    }
                })
                ->delete();

            foreach ($staffIds as $sid) {
                Assignment::create([
                    'project_id' => $project->id,
                    'staff_id' => $sid,
                    'date' => $date,
                    // 正規の役割コード以外（空・別表記）は「役割なし（''）」に倒す＝表記ゆれを入れない
                    'role' => AssignmentRole::isValid($roles[$sid] ?? null) ? $roles[$sid] : '',
                    'role2' => AssignmentRole::isValid($roles2[$sid] ?? null) ? $roles2[$sid] : null,  // 兼任（無効・空はnull）
                    'note' => $this->cleanNote($notes[$sid] ?? null),      // 担当メモ（軍師/サポ 等）
                    'patrol' => $this->cleanPatrol($patrols[$sid] ?? null), // 巡回数（数値・空はnull）
                    'remark' => $this->cleanRemark($remarks[$sid] ?? null), // 備考（一言・自由記入）
                    'status' => $data['status'],
                    // 誰が・いつ（＋確定なら確定の記録）は AssignmentStamp の1か所で付ける。
                ] + AssignmentStamp::forCreate($data['status']));
            }
        });

        $n = count($staffIds);

        return redirect('/project-assign?project=' . urlencode($project->id))
            ->with('status', "「{$project->project_name}」に {$n}名を「{$data['status']}」で保存しました（{$date}）。");
    }

    /** 担当メモを整える（前後空白を除去。空文字は null）。 */
    private function cleanNote($v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, 100);   // 念のため長さ上限
    }

    /** 巡回数を整える（数値だけ。空・非数値・0未満は null）。 */
    private function cleanPatrol($v): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }
        $n = (int) $v;

        return $n < 0 ? null : $n;
    }

    /** OPのオンライン/リアル可を短い接尾辞にする（B案）。未設定は空文字。 */
    private function opFlavor(Person $p): string
    {
        if ($p->op_online && $p->op_real) {
            return '（オ/リ）';
        }
        if ($p->op_online) {
            return '（オンライン）';
        }
        if ($p->op_real) {
            return '（リアル）';
        }

        return '';
    }

    /** 備考（一言）を整える（前後空白を除去。空文字は null。長すぎは切り詰め）。 */
    private function cleanRemark($v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, 200);
    }

    /**
     * エントリー一覧からの1人ぶんアサイン切替（A案）。
     * 「月ごと」の表でセルをクリックしたときに呼ばれ、その案件×その人（×本番日）を
     * assignments に1行だけ追加（assign）／削除（unassign）する。
     * 上書き一括保存の save() とは違い、他の人のアサインには触らない（1セルだけ動かす）。
     */
    public function quickToggle(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string'],
            'staff_id' => ['required', 'string'],
            'action' => ['required', 'in:assign,unassign'],
            'role' => ['nullable', 'string'],
            'role2' => ['nullable', 'string'],      // 兼任＝サブ役割。送られたときだけ更新。
            'status' => ['nullable', 'in:仮,確定'],
            'note' => ['nullable', 'string'],       // 担当メモ（軍師/サポ 等）。送られたときだけ更新。
            'patrol' => ['nullable'],               // 巡回数（数値／空）。送られたときだけ更新。
            'remark' => ['nullable', 'string'],     // 備考（一言）。送られたときだけ更新。
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
        $sid = $data['staff_id'];

        // 同じ案件×人×日は1行だけ（unique制約）。date は 'date' キャストで時刻付き保存になるため
        // 取りこぼし防止に whereDate（日付部分だけ）で照合する（D決めの500対策と同じ考え方）。
        $existing = Assignment::where('project_id', $project->id)
            ->where('staff_id', $sid)
            ->whereDate('date', $date)
            ->first();

        if ($data['action'] === 'assign') {
            $role = AssignmentRole::isValid($data['role'] ?? null) ? $data['role'] : '';
            $status = $data['status'] ?? '確定';
            // note/patrol/remark/role2 は「送られてきたキーだけ」更新する（役割だけ変える操作で担当・備考・兼任を消さないため）。
            $note = $this->cleanNote($data['note'] ?? null);
            $patrol = $this->cleanPatrol($data['patrol'] ?? null);
            $remark = $this->cleanRemark($data['remark'] ?? null);
            $role2 = AssignmentRole::isValid($data['role2'] ?? null) ? $data['role2'] : null;
            if ($existing) {
                $update = [
                    'status' => $status,
                    'role' => $role !== '' ? $role : $existing->role,
                ];
                if ($request->has('note')) {
                    $update['note'] = $note;
                }
                if ($request->has('patrol')) {
                    $update['patrol'] = $patrol;
                }
                if ($request->has('remark')) {
                    $update['remark'] = $remark;
                }
                if ($request->has('role2')) {
                    $update['role2'] = $role2;   // 兼任（空・無効は null＝解除）
                }
                // 仮→確定に上げた（または確定→仮に戻した）ときだけ確定の記録を動かす。
                $existing->update($update + AssignmentStamp::forUpdate($existing, $status));
            } else {
                Assignment::create([
                    'project_id' => $project->id,
                    'staff_id' => $sid,
                    'date' => $date,
                    'role' => $role,
                    'role2' => $role2,
                    'note' => $note,
                    'patrol' => $patrol,
                    'remark' => $remark,
                    'status' => $status,
                ] + AssignmentStamp::forCreate($status));
            }

            return response()->json([
                'ok' => true, 'assigned' => true, 'status' => $status,
                'note' => $existing ? $existing->fresh()->note : $note,
                'patrol' => $existing ? $existing->fresh()->patrol : $patrol,
                'remark' => $existing ? $existing->fresh()->remark : $remark,
                'role2' => $existing ? $existing->fresh()->role2 : $role2,
            ]);
        }

        // unassign：その行を消す（応募＝applications はそのまま＝また〇に戻る）。
        if ($existing) {
            $existing->delete();
        }

        return response()->json(['ok' => true, 'assigned' => false, 'status' => null]);
    }
}
