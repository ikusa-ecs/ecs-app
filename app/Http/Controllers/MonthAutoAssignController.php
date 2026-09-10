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
        $skipProjects = $this->skipProjects($request);
        $includeHandMade = $this->includeHandMade($request);

        $engine = new MonthAutoAssign($period, $office, $skipDays, $skipProjects, $includeHandMade);
        $plan = $engine->plan();

        return view('auto_assign_month', [
            // チェックボックスを並べるための一覧（外したものも含む）。
            'candidateDays' => $engine->candidateDays(),
            'candidateProjects' => $engine->candidateProjects(),
            'skipDays' => $skipDays,
            'skipProjects' => $skipProjects,
            // ⚠ 手でスタッフを入れた案件＝機械は触らない（2026-09-08 baba指摘）。
            //   黙って外すと「なぜ下見に出ないのか」が分からないので、必ず画面に出して戻せるようにする。
            'handMade' => $engine->handMadeProjects(),
            'includeHandMade' => $includeHandMade,
            // ⚠ まだスタッフに公開していない案件＝自動アサインの対象外（2026-09-09 baba要望）。
            //   黙って外すと「なぜ下見に出ないのか」が分からないので、必ず画面に理由つきで出す。
            'unpublished' => $engine->unpublishedProjects(),
            'period' => $period,
            'periodLabel' => Carbon::createFromFormat('Y-m-d', $period.'-01')->format('Y年n月'),
            'prevPeriod' => Carbon::createFromFormat('Y-m-d', $period.'-01')->subMonth()->format('Y-m'),
            'nextPeriod' => Carbon::createFromFormat('Y-m-d', $period.'-01')->addMonth()->format('Y-m'),
            'isThisMonth' => $period === Carbon::today()->format('Y-m'),
            'plan' => $plan,
            // ⚠ 表示だけ日ごとにまとめる（2026-09-07 baba要望）。
            //   埋める順（＝取り合いが厳しい案件から）は変えていない。
            //   その順番は各行の 'order' に残してあるので、画面に番号として出す。
            'byDay' => collect($plan['projects'])->groupBy('date')->sortKeys()->all(),
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
        // ⚠ 実行はPOST＝拠点はURLでなくフォームから届く。`filter()` はURLしか見ないので、
        //   ここで受け取らないと「下見は名古屋・実行は別の拠点」になる。
        $office = OfficeScope::fromValue($request->input('office'));
        $period = $this->targetPeriod($request);

        // ⚠ プレビューのあとに誰かが手で入れているかもしれないので、**作り直してから**保存する
        //   （画面が持っている古い計画をそのまま保存すると、二重に入る）。
        $plan = (new MonthAutoAssign(
            $period, $office,
            $this->skipDays($request),
            $this->skipProjects($request),
            // ⚠ 下見と同じものを受け取る。ここを渡し忘れると
            //   「下見では触らないと出ていたのに、実行したら入る」になる。
            $this->includeHandMade($request),
        ))->plan();

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

        return redirect('/auto-assign-month?'.http_build_query(array_filter(['period' => $period, 'office' => OfficeScope::param($office)])))
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

        // ⚠ 消すのは「機械が入れて、そのあと**誰も触っていない**仮」だけ。
        //   ・人が「確定」に上げたもの … 残す（人の判断を機械が消さない）
        //   ・人があとから手で直したもの … **残す**
        //     （役割を替えた・備考を書いた・担当メモを入れた行。2026-09-08 baba指摘
        //      「置いた人がいなくなっていた」＝ここが消えていた可能性がある）
        //   見分け方＝作った時刻より更新の時刻が後なら「あとから誰かが触った」。
        $removed = Assignment::where('auto_run_id', $run->id)
            ->where('status', '仮')
            ->whereColumn('updated_at', '<=', 'created_at')
            ->delete();

        $keptConfirmed = Assignment::where('auto_run_id', $run->id)->where('status', '確定')->count();
        $keptEdited = Assignment::where('auto_run_id', $run->id)
            ->where('status', '仮')
            ->whereColumn('updated_at', '>', 'created_at')
            ->count();
        $run->update(['undone' => $removed]);

        $msg = "自動アサインを取り消しました（{$removed}名）。";
        if ($keptConfirmed > 0) {
            $msg .= "⚠ {$keptConfirmed}名は「確定」になっていたので残しています（手で外してください）。";
        }
        if ($keptEdited > 0) {
            $msg .= "⚠ {$keptEdited}名は、そのあと手で直してあったので残しています（消したいときは日別ボードで外してください）。";
        }

        return redirect('/auto-assign-month?'.http_build_query(array_filter([
            'period' => $run->period, 'office' => OfficeScope::param($run->office),
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
     * 「この案件は入れない」で外した案件（2026-09-07 baba要望）。
     * ⚠ 実行のときも同じものを受け取る。プレビューで外したのに実行では入る、では意味がない。
     *
     * @return array<int, string>
     */
    private function skipProjects(Request $request): array
    {
        $raw = $request->input('skipProject', []);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => (string) $v, $raw), fn ($v) => $v !== ''));
    }

    /**
     * 「手で入っているけれど、この案件も自動で埋めてよい」と選んだ案件（2026-09-08 baba指摘）。
     *
     * ⚠ 既定は空＝**手で入っている案件には触らない**。
     *   実行のときも下見と同じものを受け取る（食い違うと「下見と違う結果」になる）。
     *
     * @return array<int, string>
     */
    private function includeHandMade(Request $request): array
    {
        $raw = $request->input('includeHand', []);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => (string) $v, $raw), fn ($v) => $v !== ''));
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
