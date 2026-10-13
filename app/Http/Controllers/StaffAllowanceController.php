<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\StaffAllowance;
use App\Support\AssignmentRole;
use App\Support\StaffAllowances;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * スタッフの手当の記録（運転・スライド・事前レクチャー・その他）。2026-10-13 baba。
 * 入口は2つ＝請求書チェック・手当管理表（/staff-invoice-check）と、マイページの収支入力（/mypage-finance）。
 * どちらもここへ送る（保存の入口は1つ）。入れられるのは社員以上（ルートのグループが tier:employee）。
 */
class StaffAllowanceController extends Controller
{
    /**
     * 入れる。画面からは JSON（収支入力）かフォーム（請求書チェック）で来る。
     * 2026-10-13 baba「宿泊手当は一部のスタッフのこともある＝複数チェックして同じ内容を入れたい」
     *   → staff_ids[]（複数）でも staff_id（1人）でも受ける。チェックした人それぞれに1行ずつ入れる。
     */
    public function store(Request $request)
    {
        // 1人だけ（staff_id）で来ても、複数（staff_ids[]）にそろえる。
        if (! $request->has('staff_ids') && $request->filled('staff_id')) {
            $request->merge(['staff_ids' => [(string) $request->input('staff_id')]]);
        }
        $data = $request->validate([
            'staff_ids' => ['required', 'array', 'min:1', 'max:60'],
            'staff_ids.*' => ['string', 'distinct', Rule::exists('people', 'id')->where('role', 'staff')],
            'project_id' => ['nullable', 'string', Rule::exists('projects', 'id')],
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::in(array_keys(StaffAllowances::KINDS))],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
            // 金額を手で入れる種類（大型手当・その他）のときだけ金額が要る。
            'amount' => ['nullable', 'integer', 'min:0', 'max:200000', Rule::requiredIf(StaffAllowances::needsAmount((string) $request->input('kind')))],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['staff_ids.required' => 'スタッフを1人以上選んでください。'],
            ['staff_ids' => 'スタッフ', 'staff_ids.*' => 'スタッフ', 'date' => '日付', 'kind' => '手当の種類', 'amount' => '金額', 'count' => '回数']);

        // 「1日分として数える（4時間以上のリハ）」の金額＝案件の実施形態の基本報酬（金額を入れていればそれ）。
        $amount = StaffAllowances::needsAmount($data['kind']) ? (int) $data['amount'] : null;
        if ($data['kind'] === StaffAllowances::SIX_HOURS) {
            $amount = isset($data['amount']) ? (int) $data['amount']
                : StaffAllowances::sixHoursAmount(! empty($data['project_id']) ? Project::find($data['project_id']) : null);
        }

        $items = [];
        foreach ($data['staff_ids'] as $sid) {
            $a = StaffAllowance::create([
                'staff_id' => $sid,
                'project_id' => $data['project_id'] ?? null,
                'date' => $data['date'],
                'kind' => $data['kind'],
                'count' => (int) ($data['count'] ?? 1),
                'amount' => $amount,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'created_by' => (string) (Auth::user()->name ?? ''),
            ]);
            $items[] = $this->row($a);
        }

        $msg = $data['kind'].'を'.count($items).'人に入れました。';
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'item' => $items[0], 'items' => $items, 'message' => $msg]);
        }

        return back()->with('ok', $msg);
    }

    /**
     * 1件消す。自動で入れた行（前日設営・リハ）は「消した印」を付けて隠す＝次に開いても入れ直さない。
     */
    public function destroy(Request $request, int $id)
    {
        $a = StaffAllowance::findOrFail($id);
        if ($a->auto_key !== null) {
            $a->dismissed_at = now();
            $a->save();
        } else {
            $a->delete();
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('ok', $a->kind.'を消しました。');
    }

    /**
     * メモをあとから書く・直す（2026-10-13 baba「メモを入れるところがない」＝自動で入った行にも書けるように）。
     */
    public function saveNote(Request $request, int $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $a = StaffAllowance::findOrFail($id);
        $a->note = trim((string) ($data['note'] ?? '')) ?: null;
        $a->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('ok', 'メモを保存しました。');
    }

    /**
     * リハ・設営の行の種類を切り替える（前日設営手当／事前リハーサル手当／1案件として数える）。
     * 2026-10-13 baba「前日設営だけかもしれないし、リハもかもしれない。6時間以上のリハ・設営は1案件にカウント（→規定は4時間以上のリハで1日分）」。
     */
    public function changeKind(Request $request, int $id)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(StaffAllowances::SWITCHABLE)]]);
        $a = StaffAllowance::findOrFail($id);
        abort_unless(in_array($a->kind, StaffAllowances::SWITCHABLE, true), 422);
        $a->kind = $data['kind'];
        StaffAllowances::markTouched($a);   // 手で切り替えた＝自動で種類を直さない
        $a->amount = $data['kind'] === StaffAllowances::SIX_HOURS
            ? StaffAllowances::sixHoursAmount($a->project_id ? Project::find($a->project_id) : null)
            : null;
        $a->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'item' => $this->row($a)]);
        }

        return back()->with('ok', '「'.$a->kind.'」に切り替えました。');
    }

    /**
     * 案件1件ぶん＝その案件に入っているスタッフと、入っている手当（収支入力の画面がその場で読む）。
     */
    public function forProject(Request $request)
    {
        $project = Project::findOrFail((string) $request->query('project', ''));
        $asg = Assignment::where('project_id', $project->id)->where('status', '!=', 'キャンセル')
            ->orderBy('date')->get(['staff_id', 'role', 'date']);
        // 前日設営・リハの手当を自動で入れておく（請求書チェックの画面と同じ・何度呼んでも同じ）。
        if ($asg->isNotEmpty()) {
            StaffAllowances::syncAuto(Carbon::parse($asg->min('date'))->startOfDay(), Carbon::parse($asg->max('date'))->startOfDay(), null, $project->id);
        }
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
            'items' => StaffAllowance::where('project_id', $project->id)->whereNull('dismissed_at')->orderBy('date')->orderBy('id')->get()
                ->map(fn ($a) => $this->row($a))->values(),
            'kinds' => StaffAllowances::KINDS,
            'hints' => StaffAllowances::HINTS,
            'switchable' => StaffAllowances::SWITCHABLE,
            'needsAmount' => array_values(array_filter(array_keys(StaffAllowances::KINDS), fn ($k) => StaffAllowances::needsAmount($k))),
        ]);
    }

    private function row(StaffAllowance $a): array
    {
        $staff = Person::whereKey($a->staff_id)->pluck('name', 'id')->all();
        $p = $a->project_id ? Project::find($a->project_id) : null;

        return StaffAllowances::row($a, $p ? [$p->id => (string) $p->project_name] : [], $staff,
            $p ? [$p->id => trim((string) ($p->client ?? ''))] : []);
    }
}
