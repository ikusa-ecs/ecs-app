<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AutoAssignRun;
use App\Models\Project;
use App\Support\AssignmentStamp;
use App\Support\MonthAutoAssign;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 月まとめの自動アサイン（/auto-assign-month）。2026-09-07 baba要望。
 *
 * 【なぜこの画面があるか】
 * 案件を1件ずつ埋めると早い者勝ちになり、希望者の少ない案件が埋まらない。
 * 月ごとにまとめて考えると、取り合いが厳しい案件から先に埋められて、
 * 「誰にどれくらい入ってもらったか」もならしやすい。
 *
 * 【3つに分けている理由】
 *  ・index（プレビュー）… **DBを一切書き換えない**。何が起きるかを先に全部見せる。
 *  ・run（実行）        … プレビューと同じ計画をもう一度作って保存する（すべて「仮」）。
 *  ・undo（取り消し）    … その回に入れた「仮」だけを消す。
 *
 * ⚠ **取り消せることが大前提。** 1か月ぶんをまとめて入れる操作は取り返しがつきにくく、
 *   戻せないと誰も押せない。実行1回ごとに番号（auto_assign_runs）を振っている。
 * ⚠ 取り消すのは「その回に入れた **仮のまま** のもの」だけ。
 *   人が「確定」に上げたものは残す＝**人の判断を機械が消さない**。
 */
class MonthAutoAssignController extends Controller
{
    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);
        $period = $this->targetPeriod($request);

        $skipDays = $this->skipDays($request);

        $engine = new MonthAutoAssign($period, $office, $skipDays);
        $plan = $engine->plan();

        return view('auto_assign_month', [
            // 「この日はアサインしない」のチェックを並べるための日の一覧（外した日も含む）。
            'candidateDays' => $engine->candidateDays(),
            'skipDays' => $skipDays,
            'period' => $period,
            'periodLabel' => Carbon::createFromFormat('Y-m-d', $period.'-01')->format('Y年n月'),
            'prevPeriod' => Carbon::createFromFormat('Y-m-d', $period.'-01')->subMonth()->format('Y-m'),
            'nextPeriod' => Carbon::createFromFormat('Y-m-d', $period.'-01')->addMonth()->format('Y-m'),
            'isThisMonth' => $period === Carbon::today()->format('Y-m'),
            'plan' => $plan,
            'officeScope' => $office,
            'monthCap' => MonthAutoAssign::MONTH_CAP,
            // 取り消せる直近の実行（まだ取り消していないもの）。
            'lastRun' => AutoAssignRun::where('period', $period)
                ->where('undone', 0)->where('added', '>', 0)
                ->latest('id')->first(),
        ]);
    }

    /** 計画どおりに保存する（すべて「仮」）。 */
    public function run(Request $request)
    {
        $office = OfficeScope::filter($request);
        $period = $this->targetPeriod($request);

        // ⚠ プレビューのあとに誰かが手で入れているかもしれないので、**作り直してから**保存する
        //   （画面が持っている古い計画をそのまま保存すると、二重に入る）。
        $plan = (new MonthAutoAssign($period, $office, $this->skipDays($request)))->plan();

        $projects = Project::whereIn('id', collect($plan['projects'])->pluck('id'))
            ->get(['id', 'start_date'])->keyBy('id');

        $added = 0;
        $run = null;
        DB::transaction(function () use ($plan, $projects, $period, $office, &$added, &$run) {
            $run = AutoAssignRun::create([
                'period' => $period,
                'office' => $office,
                'run_by' => Auth::id(),
                'added' => 0,
            ]);

            foreach ($plan['projects'] as $row) {
                $p = $projects->get($row['id']);
                if (! $p || ! $p->start_date) {
                    continue;
                }
                $date = $p->start_date->format('Y-m-d');
                foreach ($row['picks'] as $pick) {
                    // ⚠ 念のためもう一度確かめる（同じ案件・同じ日に二重に入れない）。
                    $exists = Assignment::where('project_id', $p->id)
                        ->where('staff_id', $pick['id'])
                        ->whereDate('date', $date)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    Assignment::create([
                        'project_id' => $p->id,
                        'staff_id' => $pick['id'],
                        'date' => $date,
                        'role' => $pick['role'],
                        'status' => '仮',
                        'auto_run_id' => $run->id,
                    ] + AssignmentStamp::forCreate('仮'));
                    $added++;
                }
            }

            $run->update(['added' => $added]);
        });

        return redirect('/auto-assign-month?'.http_build_query(array_filter(['period' => $period, 'office' => $office])))
            ->with('status', $added > 0
                ? "自動アサインしました。{$added}名を「仮」で入れました。内容を確かめて、必要なところは手で直してください。"
                : '入れられる人がいませんでした。稼働希望が出ているか確かめてください。');
    }

    /** その回に入れた「仮」だけを取り消す。 */
    public function undo(Request $request)
    {
        $runId = (int) $request->input('run_id');
        $run = AutoAssignRun::find($runId);
        if (! $run || $run->undone > 0) {
            return back()->with('status', 'その取り消しはすでに済んでいます。');
        }

        // ⚠ 「仮」のままのものだけ消す。人が「確定」に上げたもの・手で直したものは残す。
        $removed = Assignment::where('auto_run_id', $run->id)->where('status', '仮')->delete();
        $kept = Assignment::where('auto_run_id', $run->id)->count();
        $run->update(['undone' => $removed]);

        $msg = "自動アサインを取り消しました（{$removed}名）。";
        if ($kept > 0) {
            $msg .= "⚠ {$kept}名は「確定」になっていたので残しています（手で外してください）。";
        }

        return redirect('/auto-assign-month?'.http_build_query(array_filter([
            'period' => $run->period, 'office' => $run->office,
        ])))->with('status', $msg);
    }

    /**
     * 「この日はアサインしない」で外した日（2026-09-07 baba要望）。
     *
     * ⚠ 形が正しい日付だけを通す（URLを手で書き換えられても壊れないように）。
     * ⚠ 実行のときも同じものを受け取る。プレビューで外したのに実行では入る、では意味がない。
     *
     * @return array<int, string>
     */
    private function skipDays(Request $request): array
    {
        $raw = $request->input('skip', []);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($d) => (string) $d, $raw),
            fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        ));
    }

    /**
     * 見る月。?period=2026-10 が来ればその月、来なければ今月。
     * ⚠ 読めない形は今月として扱う（別の月に黙って書き込まないため）。
     */
    private function targetPeriod(Request $request): string
    {
        $ym = (string) $request->input('period', '');
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $ym, $m)
            && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }

        return Carbon::today()->format('Y-m');
    }
}
