<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\AssignmentRole;
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

        // すでに保存済みのアサイン（staff_id => ['role'=>, 'status'=>]）。再表示で選択済みにする。
        $existing = Assignment::where('project_id', $project->id)
            ->get()
            ->keyBy('staff_id')
            ->map(fn ($a) => ['role' => $a->role, 'status' => $a->status])
            ->all();

        // スタッフ名簿（経験回数の多い順）。区分・できる役割・NG も一緒に。
        $staff = Person::staff()
            ->with(['roleEligibilities', 'ngRelations'])
            ->orderByDesc('experience_count')
            ->get()
            ->map(function (Person $p) {
                $can = $p->roleEligibilities->pluck('position')->all();
                $posLabels = array_map(fn ($k) => AssignmentRole::label($k), $can);

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'level' => $p->skill_level ?? '—',
                    'exp' => $p->experience_count ?? 0,
                    'exclusive' => (bool) $p->is_exclusive,
                    'posLabels' => $posLabels,
                    'ng' => $p->ngRelations->pluck('partner_name')->all(),
                ];
            })
            ->values();

        // ── 警告用の集計（設計どおり「警告」であってハード制約にはしない）──
        // ① 同日・他案件にすでに割り当て済みの人（staff_id => [案件名,...]）＝ダブルブッキング検知
        $sameDay = [];
        // ② 今月のアサイン件数（staff_id => 件数）＝月20件上限の見える化
        $monthCount = [];
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

        return view('assignment', [
            'project' => $project,
            'contentName' => $contentName,
            'date' => $date,
            'staff' => $staff,
            'existing' => $existing,
            'roleLabels' => AssignmentRole::positionLabels(),
            'sameDay' => $sameDay,
            'monthCount' => $monthCount,
            'monthCap' => self::MONTH_CAP,
        ]);
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
        ]);

        $project = Project::find($data['project_id']);
        if (! $project) {
            return redirect('/projects')->with('status', 'アサインする案件が見つかりませんでした。');
        }
        if (! $project->start_date) {
            return redirect('/project-assign?project=' . urlencode($project->id))
                ->with('status', '⚠ この案件は開催日が未設定です。先に案件登録で日付を入れてからアサインしてください。');
        }

        $date = $project->start_date->format('Y-m-d');
        $staffIds = $data['staff_ids'] ?? [];
        $roles = $data['role'] ?? [];
        $now = Carbon::now();

        // 「いま選ばれている人」で、その案件×その日 を上書き保存する（外した人は削除）。
        DB::transaction(function () use ($project, $date, $staffIds, $roles, $data, $now) {
            Assignment::where('project_id', $project->id)->where('date', $date)->delete();

            foreach ($staffIds as $sid) {
                Assignment::create([
                    'project_id' => $project->id,
                    'staff_id' => $sid,
                    'date' => $date,
                    // 正規の役割コード以外（空・別表記）は「役割なし（''）」に倒す＝表記ゆれを入れない
                    'role' => AssignmentRole::isValid($roles[$sid] ?? null) ? $roles[$sid] : '',
                    'status' => $data['status'],
                    'assigned_by' => null,        // 認証導入後に操作者を入れる
                    'assigned_at' => $now,
                ]);
            }
        });

        $n = count($staffIds);

        return redirect('/project-assign?project=' . urlencode($project->id))
            ->with('status', "「{$project->project_name}」に {$n}名を「{$data['status']}」で保存しました（{$date}）。");
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
            'status' => ['nullable', 'in:仮,確定'],
        ]);

        $project = Project::find($data['project_id']);
        if (! $project) {
            return response()->json(['ok' => false, 'message' => '案件が見つかりません。'], 404);
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
            if ($existing) {
                $existing->update([
                    'status' => $status,
                    'role' => $role !== '' ? $role : $existing->role,
                ]);
            } else {
                Assignment::create([
                    'project_id' => $project->id,
                    'staff_id' => $sid,
                    'date' => $date,
                    'role' => $role,
                    'status' => $status,
                    'assigned_by' => null,          // 認証導入後に操作者を入れる
                    'assigned_at' => Carbon::now(),
                ]);
            }

            return response()->json(['ok' => true, 'assigned' => true, 'status' => $status]);
        }

        // unassign：その行を消す（応募＝applications はそのまま＝また〇に戻る）。
        if ($existing) {
            $existing->delete();
        }

        return response()->json(['ok' => true, 'assigned' => false, 'status' => null]);
    }
}
