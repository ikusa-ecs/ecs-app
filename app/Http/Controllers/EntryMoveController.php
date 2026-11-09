<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Support\ProjectHistoryRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * エントリーを別の案件へ移す（2026-11-09 小沼さん）。
 *
 * 【なぜ要るか】
 *   アサイン表の取込で、案件の中身が丸ごと別の案件に書き換わることがある
 *   （例：P-2026-1003 が 10/29 に「電脳謎パ（NTT）」→「捜査会議（SCSK）」）。
 *   案件はそのままでも、**スタッフが押したときに選んだ案件**にエントリーを戻したい。
 *   それまでは画面が無く、本番のデータを直接直すしかなかった。
 *
 * 【移すもの・移さないもの】
 *   ・移すのはエントリー（applications）の行だけ。希望／可・一言・エントリーした日時はそのまま。
 *   ・アサイン（assignments）は動かさない（シートの取込や画面で入れた別のもの）。
 *   ・移す先にすでにその人のエントリーがあれば、移さずに知らせる（同じ人×同じ案件は1行だけ）。
 *   ・両方の案件の編集履歴に「誰を移したか」を残す。
 */
class EntryMoveController extends Controller
{
    public function show(Request $request)
    {
        $fromId = trim((string) $request->query('from', ''));
        $toId = trim((string) $request->query('to', ''));
        $from = $fromId !== '' ? Project::find($fromId) : null;
        $to = $toId !== '' ? Project::find($toId) : null;

        $rows = [];
        if ($from) {
            $apps = Application::where('project_id', $from->id)->orderBy('created_at')->get();
            $people = Person::whereIn('id', $apps->pluck('staff_id'))->get(['id', 'name'])->keyBy('id');
            $onTo = $to ? Application::where('project_id', $to->id)->pluck('staff_id')->all() : [];
            $assigned = Assignment::where('project_id', $from->id)->pluck('role', 'staff_id')->all();
            foreach ($apps as $a) {
                $rows[] = [
                    'staff_id' => (string) $a->staff_id,
                    'name' => $people[$a->staff_id]->name ?? $a->staff_id,
                    'intent' => $a->intent,
                    'note' => $a->note,
                    'at' => optional($a->created_at)->format('Y-m-d H:i'),
                    'already' => in_array($a->staff_id, $onTo, true),
                    'assigned' => $assigned[$a->staff_id] ?? null,
                ];
            }
        }

        return view('entry_move', [
            'fromId' => $fromId,
            'toId' => $toId,
            'from' => $from,
            'to' => $to,
            'fromLabel' => $from ? self::label($from) : null,
            'toLabel' => $to ? self::label($to) : null,
            'rows' => $rows,
        ]);
    }

    public function move(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string', 'different:from'],
            'staff' => ['required', 'array', 'min:1'],
            'staff.*' => ['string'],
        ], [
            'to.different' => '移す元と移す先が同じ案件です。',
            'staff.required' => '移す人を1人以上選んでください。',
        ]);

        $from = Project::find($data['from']);
        $to = Project::find($data['to']);
        if (! $from || ! $to) {
            return back()->withErrors(['from' => '案件IDが見つかりません。']);
        }

        $moved = [];
        $skipped = [];
        DB::transaction(function () use ($data, $from, $to, &$moved, &$skipped) {
            $names = Person::whereIn('id', $data['staff'])->pluck('name', 'id');
            foreach (array_unique($data['staff']) as $sid) {
                $app = Application::where('project_id', $from->id)->where('staff_id', $sid)->first();
                if (! $app) {
                    continue;
                }
                $name = $names[$sid] ?? $sid;
                if (Application::where('project_id', $to->id)->where('staff_id', $sid)->exists()) {
                    $skipped[] = $name;

                    continue;
                }
                // ⚠ 行を作り直さず project_id だけ変える＝エントリーした日時（created_at）を残す。
                $app->project_id = $to->id;
                $app->timestamps = false;
                $app->save();
                $moved[] = $name;
            }

            if ($moved) {
                $list = implode('・', $moved);
                ProjectHistoryRecorder::recordNote($from, 'エントリーを移した', $list, $to->id.' へ');
                ProjectHistoryRecorder::recordNote($to, 'エントリーを移した', $from->id.' から', $list);
            }
        });

        $msg = count($moved).'人のエントリーを '.$to->id.' へ移しました。';
        if ($skipped) {
            $msg .= ' 移す先にすでにエントリーがあったので移さなかった人：'.implode('・', $skipped).'。';
        }

        return redirect('/entry-move?from='.urlencode($from->id).'&to='.urlencode($to->id))->with('status', $msg);
    }

    /** 画面に出す案件の名前＝「12/11 太陽誘電株式会社／電脳都市」。 */
    private static function label(Project $p): string
    {
        $d = $p->start_date ? \Carbon\Carbon::parse($p->start_date)->format('Y/n/j') : '日付なし';

        return $d.'　'.trim(($p->client ? $p->client.'／' : '').$p->project_name);
    }
}
