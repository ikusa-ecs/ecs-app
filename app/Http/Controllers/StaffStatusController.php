<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;

/**
 * 稼働状況（/staff-status）。スタッフごとの稼働指標を DB から計算して画面に渡す。
 *
 * 指標の出どころ（今日決めた定義）：
 *  ・稼働率 ＝ 今月アサイン日数 ÷ 希望日数（希望充足率と同一。1指標に統合）。希望0件は「—」。
 *  ・cap=20 は稼働率の分母ではなく「月上限」（働きすぎ防止の目安。表示と警告に使う）。
 *  ・今月アサイン数＝対象月の本番アサイン数（projects.date_type='本番'）。
 *  ・最大連勤＝全アサイン日のうち連続日数の最長。
 *  ・ご無沙汰＝基準日以前で最後にアサインされてからの経過日数（無ければ「—」）。
 *  ・選ばれた率＝応募(applications)のうち実アサインされた割合。
 *  ・通算＝people.experience_count。区分（新人/中堅/ベテラン）は通算から画面側で判定。
 *  ・活性度＝対象月の希望日数 ÷ 対象月の本番開催日数（50%↑=アクティブ/30%↑=準/未満=非）。
 *
 * 対象月は「今日の当月」（例：7月に開けば2026-07。運用ではアサインMTGの対象月）。
 */
class StaffStatusController extends Controller
{
    public function index()
    {
        return view('staff_status', ['status' => $this->buildStatus()]);
    }

    /**
     * 稼働指標の一覧を組み立てて返す（スタッフ画面の「稼働状況」タブでも再利用する）。
     * 画面（view）に依存しないので、他のコントローラからも呼べる。
     */
    public function buildStatus()
    {
        $today = Carbon::today();
        // 対象月＝今日の当月（当月の1日〜末日）。period は '2026-07' の形の月キー。
        $period = $today->format('Y-m');
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        // 案件ID → date_type（本番/予備日/リハ等）。回数は本番のみ数えるため。
        $projectType = Project::pluck('date_type', 'id');

        // 対象月の本番開催日数（活性度の分母）
        $eventDays = Project::where('date_type', '本番')
            ->whereBetween('start_date', [$monthStart, $monthEnd])
            ->get()
            ->map(fn (Project $p) => optional($p->start_date)->format('Y-m-d'))
            ->filter()->unique()->count();

        // キャンセルされたアサインは数えない（/assign・マイページ・スタッフ画面と同じ扱いにそろえる）。
        // ここだけ全件を数えていると、同じ人の稼働数・連勤・選ばれた率が他画面とズレるため。
        $assignsByStaff = Assignment::where('status', '!=', 'キャンセル')->get()->groupBy('staff_id');
        $prefByStaff = ShiftPreference::where('period', $period)->available()->get()->groupBy('staff_id');
        $appsByStaff = Application::all()->groupBy('staff_id');

        $status = Person::staff()->orderByDesc('experience_count')->get()
            ->map(function (Person $p) use ($today, $monthStart, $monthEnd, $projectType, $eventDays, $assignsByStaff, $prefByStaff, $appsByStaff) {
                $myAssigns = $assignsByStaff->get($p->id, collect());

                // 今月アサイン数（対象月・本番のみ）
                $month = $myAssigns->filter(function (Assignment $a) use ($monthStart, $monthEnd, $projectType) {
                    return $a->date
                        && $a->date->between($monthStart, $monthEnd)
                        && $projectType->get($a->project_id) === '本番';
                })->count();

                // 希望日数（対象月の「稼働可/希望」の日数）＝稼働率の分母
                $want = $prefByStaff->get($p->id, collect())->count();
                $rate = $want > 0 ? (int) round($month / $want * 100) : null;

                // 最大連勤（全アサイン日で最長連続）
                $dates = $myAssigns->pluck('date')->filter()
                    ->map(fn (Carbon $d) => $d->format('Y-m-d'))->unique()->sort()->values();
                $renkin = $this->maxConsecutive($dates);

                // ご無沙汰（基準日以前で最後にアサインされてからの日数）
                $lastPast = $myAssigns->pluck('date')->filter()
                    ->filter(fn (Carbon $d) => $d->lte($today))->max();
                $lastDays = $lastPast ? (int) abs($today->diffInDays($lastPast)) : null;

                // 選ばれた率（応募のうち実アサインされた割合）
                $myApps = $appsByStaff->get($p->id, collect());
                $applied = $myApps->count();
                $assignedProjectIds = $myAssigns->pluck('project_id')->unique();
                $picked = $myApps->filter(fn (Application $a) => $assignedProjectIds->contains($a->project_id))->count();

                // 活性度（対象月の希望日数 ÷ 本番開催日数）
                $ratio = $eventDays > 0 ? $want / $eventDays : 0;
                $active = $ratio >= 0.5 ? 'active' : ($ratio >= 0.3 ? 'semi' : 'inactive');

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    // 拠点で絞って見るために渡す（2026-08-25 baba要望）。
                    'office' => $p->office ?? '',
                    'active' => $active,
                    'month' => $month,
                    'cap' => 20,
                    'rate' => $rate,            // 稼働率＝月÷希望日数（希望0件は null）
                    'renkin' => $renkin,
                    'total' => (int) ($p->experience_count ?? 0),
                    'zeroPref' => $want === 0,
                    'applied' => $applied,
                    'picked' => $picked,
                    'lastDays' => $lastDays,    // ご無沙汰（無ければ null）
                ];
            })->values();

        return $status;
    }

    /** 並んだ日付（Y-m-d昇順・重複なし）の中で最長の連続日数を返す。 */
    private function maxConsecutive($dates): int
    {
        $best = 0;
        $cur = 0;
        $prev = null;
        foreach ($dates as $d) {
            $c = Carbon::parse($d);
            if ($prev && (int) abs($c->diffInDays($prev)) === 1) {
                $cur++;
            } else {
                $cur = 1;
            }
            $best = max($best, $cur);
            $prev = $c;
        }
        return $best;
    }
}
