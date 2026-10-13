<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\StaffAllowance;
use App\Support\AssignmentRole;
use App\Support\StaffAllowances;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * スタッフの手当の記録（運転・スライド・事前レクチャー・その他）。2026-10-13 baba。
 * 入口は2つ＝請求書チェック・手当管理表（/staff-invoice-check）と、マイページの収支入力（/mypage-finance）。
 * どちらもここへ送る（保存の入口は1つ）。入れられるのは社員以上（ルートのグループが tier:employee）。
 */
class StaffAllowanceController extends Controller
{
    /** 1件入れる。画面からは JSON（収支入力）かフォーム（請求書チェック）で来る。 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'string', Rule::exists('people', 'id')->where('role', 'staff')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'id')],
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::in(array_keys(StaffAllowances::KINDS))],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
            // その他のときだけ金額が要る。
            'amount' => ['nullable', 'integer', 'min:0', 'max:200000', Rule::requiredIf($request->input('kind') === 'その他')],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['staff_id' => 'スタッフ', 'date' => '日付', 'kind' => '手当の種類', 'amount' => '金額', 'count' => '回数']);

        $a = StaffAllowance::create([
            'staff_id' => $data['staff_id'],
            'project_id' => $data['project_id'] ?? null,
            'date' => $data['date'],
            'kind' => $data['kind'],
            'count' => (int) ($data['count'] ?? 1),
            'amount' => $data['kind'] === 'その他' ? (int) $data['amount'] : null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'created_by' => (string) (Auth::user()->name ?? ''),
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'item' => $this->row($a)]);
        }

        return back()->with('ok', $a->kind.'を入れました。');
    }

    /** 1件消す。 */
    public function destroy(Request $request, int $id)
    {
        $a = StaffAllowance::findOrFail($id);
        $a->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('ok', $a->kind.'を消しました。');
    }

    /**
     * 案件1件ぶん＝その案件に入っているスタッフと、入っている手当（収支入力の画面がその場で読む）。
     */
    public function forProject(Request $request)
    {
        $project = Project::findOrFail((string) $request->query('project', ''));
        $asg = Assignment::where('project_id', $project->id)->where('status', '!=', 'キャンセル')
            ->orderBy('date')->get(['staff_id', 'role', 'date']);
        $people = Person::staff()->whereIn('id', $asg->pluck('staff_id'))->pluck('name', 'id');

        $staff = [];
        foreach ($asg as $a) {
            if (! isset($people[$a->staff_id]) || isset($staff[$a->staff_id])) {
                continue;
            }
            $staff[$a->staff_id] = ['id' => $a->staff_id, 'name' => $people[$a->staff_id],
                'role' => AssignmentRole::label((string) $a->role), 'date' => optional($a->date)->format('Y-m-d')];
        }

        return response()->json([
            'ok' => true,
            'date' => optional($project->start_date)->format('Y-m-d'),
            'staff' => array_values($staff),
            'items' => StaffAllowance::where('project_id', $project->id)->orderBy('date')->orderBy('id')->get()
                ->map(fn ($a) => $this->row($a))->values(),
            'kinds' => StaffAllowances::KINDS,
            'hints' => StaffAllowances::HINTS,
        ]);
    }

    private function row(StaffAllowance $a): array
    {
        $staff = Person::whereKey($a->staff_id)->pluck('name', 'id')->all();
        $proj = $a->project_id ? Project::whereKey($a->project_id)->pluck('project_name', 'id')->all() : [];

        return StaffAllowances::row($a, $proj, $staff);
    }
}
