<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use App\Support\AssignmentRole;
use App\Support\AssignmentStamp;
use App\Support\DirectorSync;
use App\Support\Departments;
use App\Support\OfficeScope;
use App\Support\PersonNotes;
use App\Support\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D決め（S-017 / /assign-director）。月次MTGで各案件の
 * D（ディレクター）／SD（サブディレクター）をカレンダー上で決める画面。
 *
 * 作りの型（このアプリ共通）：
 *   Controller が view([...]) にデータを渡す → Blade が window.ECS_* = @json(...) でJSへ橋渡し
 *   → DBが空ならBlade側で既存の見本（cases.js）にフォールバックする。
 *
 * Dの保存先：assignments テーブルを role='D' / role='SD' で使う。
 *   （案件 × スタッフ × 日 が1行。D・SDは別の人なので unique 制約に収まる）
 *   ※ D/SD の決定は assignments に一本化する（2026-08-05 baba確定）。
 *     ただし移行①の間は、古い列（projects.director_id / sd_id）にも同じ値を「写し」で書く
 *     ＝表示がまだ古い列を読んでいる画面を壊さないため（App\Support\DirectorSync::mirrorToProject）。
 *     写しをやめるのは、表示の切替が全部終わったあと（移行③）。
 */
class AssignDirectorController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();

        // 拠点の表示範囲（全拠点運用・設計書19.2／2026-08-05 baba確定＝D決めは第1弾）。
        // 管理者以上はスイッチで選んだ拠点（未選択＝全拠点）、一般社員は自拠点固定。null＝絞らない。
        $officeScope = OfficeScope::filter($request);

        // この画面に出す案件＝完了/下書き以外。拠点で絞るときは「登録拠点」＋「共有された案件」。
        // ※ 先に案件を確定させてから、その案件のD/SD/FCだけを引く（他拠点の分まで数えない）。
        $projects = OfficeScope::applyToProjects(Project::query(), $officeScope)
            ->notCancelled()   // キャンセルになった案件はDを決めない（2026-08-26）
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true))
            ->values();
        $projectIds = $projects->pluck('id')->all();

        // この案件のD/SDを assignments から引く（role='D' / 'SD'）。
        // project_id => ['D'=>['id'=>staff_id,'status'=>...], 'SD'=>...]
        $dirByProject = Assignment::whereIn('project_id', $projectIds)
            ->whereIn('role', [AssignmentRole::D, AssignmentRole::SD])
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'role', 'status'])
            ->groupBy('project_id')
            ->map(function ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $out[$r->role] = ['id' => $r->staff_id, 'status' => $r->status];
                }
                return $out;
            });

        // FC（フロアコーディネーター）もこの画面で選べるようにする。
        // project_id => [staff_id, ...]（role='FC'・キャンセル除く・重複排除）。
        $fcByProject = Assignment::whereIn('project_id', $projectIds)
            ->where('role', AssignmentRole::FC)
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->values()->all());

        // 拠点で絞っても「すでにD/SD/FCに入っている他拠点の社員」は残す。
        // 理由：保存は「いま画面に出ている人で上書き」なので、候補から消えると保存時に担当が外れてしまう。
        $keepIds = $dirByProject
            ->flatMap(fn ($roles) => collect($roles)->pluck('id')->all())
            ->merge($fcByProject->flatten())
            ->filter()
            ->unique()
            ->values()
            ->all();

        // マスに並べる社員＝自拠点の社員（people の role='employee'。管理者が全拠点表示なら全員）。
        // 既定はイベプラ＋新人だけ表示、画面の「＋全社員」で全員に広げる（front 側で出し分け）。
        // 同姓の取り違えを防ぐため、保存・判定はすべて社員ID基準で行う。
        // 並びは「その拠点のイベプラ」を先頭に、あとは氏名順（baba要望 2026-08-24）。
        // ⚠ 「アサインの候補に出さない」社員は並べない（2026-08-26 baba要望）。
        //   ただし すでにD/SD/FCに入っている人（$keepIds）は残す＝保存で担当が外れないように。
        $employees = OfficeScope::applyToPeople(Person::employees(), $officeScope, $keepIds)
            ->inAssignPool($keepIds)
            ->plannersOfOfficeFirst($officeScope)
            ->get()
            ->map(fn (Person $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'surname' => $this->surname($p->name),       // 例: '田中 健一' → '田中'
                // 本人の拠点。⚠ 表示中の拠点と違う人には画面で札を出す（2026-09-01 baba報告）。
                //   「東京と書いてあるのに福岡の人が出ている」＝すでに担当に入っているので
                //   残している（keepIds）か、名簿の拠点が未設定か、のどちらか。
                //   どちらなのかが画面で分かるようにする。空は未設定として空文字で渡す。
                'office' => (string) ($p->office ?? ''),
                'department' => $p->department,               // 実際の所属名（10種類）
                'deptCode' => Departments::code($p->department), // 色分け用（3つ以外は other）
                // 既定表示の対象＝イベプラ。兼務でイベプラに入っている人も含める。
                'planner' => $p->hasDepartment(Departments::PLANNER),
                'newbie' => $p->skill_level === '新人',       // 在籍1年未満＝既定表示の対象
            ])
            ->values();

        // コンテンツID → コンテンツ名（見出し用）
        $contentNames = Content::pluck('content_name', 'id');

        // カレンダーに並べる案件（上で拠点・状態を絞り込んだもの）を画面用の形に詰め替える。
        $cases = $projects
            ->map(function (Project $p) use ($today, $dirByProject, $fcByProject, $contentNames) {
                $start = $p->start_date;
                $off = $start ? (int) $today->diffInDays($start, false) : 0;

                $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
                $content = $firstContentId
                    ? ($contentNames[$firstContentId] ?? $p->project_name)
                    : $p->project_name;

                // 実施形態のコード。⚠ ここに判定を書かない。
                //   正本＝App\Support\ProjectFormats::countCode（cases.js の ECS_fmtCode と同じ規則）。
                //   未設定は空のまま＝勝手に「リアル」と決めない。
                $format = (string) ($p->format ?? '');
                $fmt = trim($format) === '' ? '' : \App\Support\ProjectFormats::countCode($format);

                // 決まっているD/SD（社員ID。未決は null）
                $decided = $dirByProject->get($p->id, []);

                return [
                    'id' => $p->id,
                    'off' => $off,
                    'name' => $p->project_name,
                    'client' => $p->client ?? '',
                    'content' => $content,
                    'scale' => $p->scale ?? '中型',
                    'format' => $format,
                    'fmt' => $fmt,
                    'dirId' => $decided['D']['id'] ?? null,    // D の社員ID
                    'sdId' => $decided['SD']['id'] ?? null,    // SD の社員ID
                    'fcIds' => $fcByProject->get($p->id, []),  // FC の社員ID（複数可）
                    'dStatus' => $decided['D']['status'] ?? null,
                    'sStatus' => $decided['SD']['status'] ?? null,
                    'dayType' => $p->date_type ?? '本番',
                    'status' => $p->status ?? '',
                    'guests' => $p->guest_count,
                    'teams' => $p->team_count,
                    'repeat' => (bool) $p->is_repeat,
                    'evStart' => $p->event_start_time,
                    'evEnd' => $p->event_end_time,
                    'meet' => $p->start_time,
                    'leave' => $p->end_time,
                    'date' => optional($start)->format('Y-m-d'),  // 保存に使う実日付
                ];
            })
            ->values();

        // D/SD/FC 以外のロール（OP・MC等）でその日アサイン済みの社員（チップの色分け用）。
        // FCはこの画面で選べるので $fcByProject 側で扱い、ここでは二重に数えないよう除外する。
        // 形：日付(Y-m-d) => [ staff_id => [role, ...] ]
        // ※ 他拠点の案件で稼働している分も「その日は埋まっている」として数える（本人の予定なので拠点は問わない）。
        //   画面に出ている社員の分だけ渡す（見えない人の予定は使わないため）。
        $empBusy = [];
        Assignment::whereIn('staff_id', $employees->pluck('id')->all())
            ->whereNotIn('role', [AssignmentRole::D, AssignmentRole::SD, AssignmentRole::FC])
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id', 'date', 'role'])
            ->each(function ($a) use (&$empBusy) {
                $key = Carbon::parse($a->date)->format('Y-m-d');
                $empBusy[$key][$a->staff_id][] = $a->role;
            });

        // D/SD/FC に入っているのに社員一覧に居ない人（＝スタッフや退職者）の名前。
        // ⚠ これが無いと画面が名前を見つけられず、`S-015` のようなIDがそのまま出る（2026-08-26 baba指摘）。
        //   例：アサイン表の取込で、Dやフロアの欄に書かれていた名前がスタッフ名簿の人と一致した場合。
        // 消さずに「名前（スタッフ）」と出す＝誰なのか分かるようにし、担当を外す判断ができるようにする。
        $otherIds = array_values(array_diff($keepIds, $employees->pluck('id')->all()));
        $others = Person::whereIn('id', $otherIds)
            ->get(['id', 'name', 'role'])
            ->mapWithKeys(fn (Person $p) => [$p->id => [
                'name' => $p->name,
                'surname' => $this->surname($p->name),
                'kind' => $p->role === 'staff' ? 'スタッフ' : '社員以外',
            ]]);

        // その日「お休み」の社員（2026-09-01 baba要望）。
        // ⚠ それまでこの画面は出勤可能日（shift_preferences）を**まったく見ていなかった**。
        //   休みの見た目（CSSの .c-dayoff / .dc-offwarn）だけがあって、中身が無い状態だった
        //   ＝休みの人がふつうに候補として並んでいた（babaの指摘どおり）。
        // 形： { "E-001": { "2026-9-6": true, ... }, ... }（画面のキーは "Y-M-D"・ゼロ埋めなし）
        // ⚠ お休み＝「NG」と「希望休」だけ。「未定（△）」は休みにしない（入れるかもしれないため）。
        $dayOff = [];
        ShiftPreference::query()
            ->whereIn('staff_id', $employees->pluck('id')->all())
            ->whereIn('availability', ['NG', '希望休'])
            ->get(['staff_id', 'date'])
            ->each(function (ShiftPreference $sp) use (&$dayOff) {
                if (! $sp->date) {
                    return;
                }
                $dayOff[$sp->staff_id][$sp->date->year.'-'.$sp->date->month.'-'.$sp->date->day] = true;
            });

        return view('assign_director', [
            'cases' => $cases,
            'employees' => $employees,
            'others' => $others,
            'empBusy' => $empBusy,
            'dayOff' => $dayOff,
            // 人ごとのメモ（2026-09-02 baba要望）。社員名を押したときのふきだしに出す。
            // 例：「10/3 大型入ってるからアサインしない」。正本＝App\Support\PersonNotes。
            'personNotes' => PersonNotes::forMany($employees->pluck('id')->all()),
            'officeScope' => $officeScope,   // 今絞っている拠点（null＝全拠点）。画面の注記に使う。
            // 「DBに社員がいるか」（拠点で絞る前）。絞った結果が0人でも見本データに戻らないようにするための旗。
            // ※ これが false のとき（DBが空の環境）だけ、画面は従来どおり見本(cases.js)を表示する。
            'usingDb' => Person::employees()->exists(),
        ]);
    }

    /**
     * 人ごとのメモを保存する（POST /assign-director/person-note）。2026-09-02 baba要望。
     *
     * ⚠ 他人のメモも書ける＝これは「その人あての個人情報」ではなく、
     *   アサインを決めるための**業務のメモ**（できるポジション・NGペアと同じ扱い）。
     *   社員以上なら書ける（ルートの区画で社員以上に絞ってある）。
     */
    public function savePersonNote(Request $request)
    {
        $data = $request->validate([
            'person_id' => ['required', 'string', 'exists:people,id'],
            'note' => ['nullable', 'string', 'max:'.PersonNotes::MAX],
        ], [], ['note' => 'メモ']);

        $note = PersonNotes::put(
            $data['person_id'],
            $data['note'] ?? '',
            optional(\Illuminate\Support\Facades\Auth::user())->id
        );

        return response()->json(['ok' => true, 'note' => $note]);
    }

    /**
     * D/SD の決定を保存する。
     * フォームは案件ごとに「D社員ID」「SD社員ID」を送る想定。
     * assignments を role='D' / 'SD' で、その案件×その人×開催日に1行ずつ保存する。
     * 外された（未定/なしに戻された）D・SDは削除する。
     *
     * 受け取る形：
     *   dir[<project_id>]  = <社員ID or 空>
     *   sd[<project_id>]   = <社員ID or 空>
     *   status             = '仮' or '確定'（既定: 仮）
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'dir' => ['nullable', 'array'],
            'dir.*' => ['nullable', 'string'],
            'sd' => ['nullable', 'array'],
            'sd.*' => ['nullable', 'string'],
            'fc' => ['nullable', 'array'],
            'fc.*' => ['nullable', 'array'],
            'fc.*.*' => ['nullable', 'string'],
            'status' => ['nullable', 'in:仮,確定'],
        ]);

        // 状態（仮／確定）は「送られてきたときだけ」使う。
        // ⚠ 既定を '仮' にして既存行にも書くと、確定済みのD・SD・FCがこの画面の保存で
        //   「仮」へ落ちてしまう（＝確定が壊れる）。null＝既存行の状態は変えない、
        //   新しく作る行だけ '仮' から始める、という扱いにする。
        $status = $data['status'] ?? null;
        $newStatus = $status ?? '仮';
        $dirs = $data['dir'] ?? [];
        $sds = $data['sd'] ?? [];
        $fcs = $data['fc'] ?? [];   // [案件ID => [社員ID, ...]]（FCは複数可）

        // 対象になる案件IDを集める（D・SD・FC のいずれかが送られてきた案件）
        $projectIds = collect(array_keys($dirs))
            ->merge(array_keys($sds))
            ->merge(array_keys($fcs))
            ->unique()
            ->values();

        // 拠点チェック＝自分が書き換えてよい案件だけに絞る（他拠点の案件は黙って除外し、
        // 件数だけ知らせる）。この画面は複数案件をまとめて保存するので、1件のせいで
        // 全部が止まらないように「弾く」のではなく「外す」形にしている。
        $targets = Project::whereIn('id', $projectIds)->get();
        $allowed = $targets->filter(fn (Project $p) => ProjectAccess::canEdit($p));
        $blocked = $targets->count() - $allowed->count();
        $projectIds = $allowed->pluck('id')->values();

        // 開催日を引く（assignments は日付必須）。日付の無い案件はスキップ。
        $startDates = $allowed->pluck('start_date', 'id');

        $saved = 0;
        $skipped = [];

        DB::transaction(function () use ($projectIds, $dirs, $sds, $fcs, $startDates, $status, $newStatus, &$saved, &$skipped) {
            foreach ($projectIds as $pid) {
                $start = $startDates[$pid] ?? null;
                if (! $start) {
                    $skipped[] = $pid;   // 開催日未設定の案件はDを保存できない
                    continue;
                }
                $date = Carbon::parse($start)->format('Y-m-d');

                // いま選ばれているD/SDの社員ID（未定／なしは空）
                $dId = trim((string) ($dirs[$pid] ?? ''));
                $sId = trim((string) ($sds[$pid] ?? ''));
                $keep = array_values(array_filter([$dId, $sId], fn ($x) => $x !== ''));

                // 以前のD/SDで「今回は選ばれていない人」の行だけ消す（担当を外す操作を反映）。
                // ※ 日付は whereDate で「日付部分」だけ照合する（DB保存は 00:00:00 付きのため、
                //   文字列 'Y-m-d' の完全一致だと取りこぼして重複エラーになる）。
                Assignment::where('project_id', $pid)
                    ->whereDate('date', $date)
                    ->whereIn('role', [AssignmentRole::D, AssignmentRole::SD])
                    ->when($keep, fn ($q) => $q->whereNotIn('staff_id', $keep))
                    ->delete();

                // 選ばれたD/SDを保存。同じ案件×人×日が既にあれば role/status を更新するだけ。
                // ※ 既存行を探すときも whereDate で「日付部分」だけ照合する（DB保存は 00:00:00 付き。
                //   updateOrCreate は date を完全一致で探すため取りこぼし→新規作成→重複エラーになる）。
                foreach ([AssignmentRole::D => $dId, AssignmentRole::SD => $sId] as $role => $staffId) {
                    if ($staffId === '') {
                        continue;   // 未定／なし＝行を作らない
                    }
                    $existing = Assignment::where('project_id', $pid)
                        ->where('staff_id', $staffId)
                        ->whereDate('date', $date)
                        ->first();
                    if ($existing) {
                        $existing->update(
                            ['role' => $role]
                            + ($status !== null ? ['status' => $status] : [])
                            + AssignmentStamp::forUpdate($existing, $status)
                        );
                    } else {
                        Assignment::create([
                            'project_id' => $pid,
                            'staff_id' => $staffId,
                            'date' => $date,
                            'role' => $role,
                            'status' => $newStatus,
                        ] + AssignmentStamp::forCreate($newStatus));
                    }
                    $saved++;
                }

                // 古い列（projects.director_id / sd_id）へ「写し」を書く＝移行①（2026-08-05 baba確定）。
                // 表示がまだ古い列を読んでいる画面（案件一覧・アサイン表など）に、
                // D決め画面で決めた担当がちゃんと出るようにするため。写しをやめるのは移行③。
                $projectModel = Project::find($pid);
                if ($projectModel) {
                    DirectorSync::mirrorToProject($projectModel, $dId ?: null, $sId ?: null);
                }

                // FC（複数可）を同期：いま選ばれているFC集合に揃える。
                // 集合に無い既存FCは削除し、有るものは作成／更新する。
                $fcIds = array_values(array_filter(
                    array_map(fn ($x) => trim((string) $x), (array) ($fcs[$pid] ?? [])),
                    fn ($x) => $x !== ''
                ));
                Assignment::where('project_id', $pid)
                    ->whereDate('date', $date)
                    ->where('role', AssignmentRole::FC)
                    ->when($fcIds, fn ($q) => $q->whereNotIn('staff_id', $fcIds))
                    ->delete();
                foreach ($fcIds as $sid) {
                    // 同じ人が同案件でD/SDに就いている場合は、その役割を優先（FCは付けない）。
                    if ($sid === $dId || $sid === $sId) {
                        continue;
                    }
                    $existing = Assignment::where('project_id', $pid)
                        ->where('staff_id', $sid)
                        ->whereDate('date', $date)
                        ->first();
                    if ($existing) {
                        $existing->update(
                            ['role' => AssignmentRole::FC]
                            + ($status !== null ? ['status' => $status] : [])
                            + AssignmentStamp::forUpdate($existing, $status)
                        );
                    } else {
                        Assignment::create([
                            'project_id' => $pid,
                            'staff_id' => $sid,
                            'date' => $date,
                            'role' => AssignmentRole::FC,
                            'status' => $newStatus,
                        ] + AssignmentStamp::forCreate($newStatus));
                    }
                    $saved++;
                }
            }
        });

        // 状態を送っていないとき（＝既存の仮/確定をそのまま保つ）は文言も変える。
        $msg = $status === null
            ? "D／SD／FCの担当を {$saved} 件保存しました（すでに確定していた担当はそのまま確定のままです）。"
            : "D／SD／FCの担当を {$saved} 件、「{$status}」で保存しました。";
        if (! empty($skipped)) {
            $msg .= '（開催日が未設定の案件 ' . count($skipped) . ' 件は保存できませんでした）';
        }
        if ($blocked > 0) {
            $msg .= "（他の拠点の案件 {$blocked} 件は編集できないため保存していません）";
        }

        // ⚠ 保存したら、**見ていた月へ戻す**（2026-09-02 baba報告）。
        //   これが無いと、保存のたびに既定の月へ飛ばされて「8月に戻っちゃう」ことになる。
        //   拠点の切替も持ち回す（管理者が拠点を選んで作業しているため）。
        $back = '/assign-director';
        $query = array_filter([
            'ym' => preg_match('/^\d{4}-\d{2}$/', (string) $request->input('ym')) ? $request->input('ym') : null,
            'office' => $request->input('office') ?: null,
        ]);
        if ($query) {
            $back .= '?'.http_build_query($query);
        }

        return redirect($back)->with('status', $msg);
    }

    /** 氏名から姓だけを取り出す（'田中 健一' → '田中'。全角/半角スペース対応）。 */
    private function surname(string $name): string
    {
        $parts = preg_split('/[\s\x{3000}]+/u', trim($name));

        return $parts[0] ?? $name;
    }
}
