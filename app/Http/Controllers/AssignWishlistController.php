<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\StaffRoleEligibility;
use App\Support\WishCount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 希望まとめ（/assign-wishlist・別ウィンドウ）。
 *
 * 稼働希望を出しているスタッフの一覧を DB から作る。
 * 対象月＝既定は今日の当月。?period=YYYY-MM で前後の月に切り替えられる（2026-09-07 baba要望）。
 * ⚠ それまでは当月に固定で、来月の希望をまとめて見ることができなかった。
 * 画面の絞り込み・並べ替え・カード集計は元の JavaScript をそのまま使い、
 * その材料（people 配列）だけを本物のデータに差し替える。
 *
 * 指標の定義はアサインダッシュボード／稼働状況と完全に同じ（画面間で数字をブレさせない）：
 *  ・希望日数(wish)   ＝ 対象月の shift_preferences（稼働可）の日数。
 *  ・アサイン済(assigned)＝ 対象月の本番アサイン日数（projects.date_type='本番'・非キャンセル）。
 *  ・アサイン割合       ＝ assigned ÷ wish（画面側で計算）。
 *  ・MCアサイン回数(mc) ＝ 対象月の本番アサインのうち役割=MC の回数。
 *  ・区分(lv)          ＝ 通算回数(experience_count)で判定（〜10新人/11〜29中堅/30〜ベテラン）。
 *  ・できるポジション(pos)＝ staff_role_eligibility（D/OP/MC/FC/CK/軍師・サポーター/受付）。
 *
 * 一覧に出すのは「希望を出した人」だけ（wish>0）。希望0件の人はこの画面の対象外。
 */
class AssignWishlistController extends Controller
{
    /** DBのポジション記号 → 画面の表示ラベル。並び順もこの順で揃える。 */
    private const POS_LABELS = [
        'D'   => 'D',
        'OP'  => 'OP',
        'MC'  => 'MC',
        'FC'  => 'FC',
        'CK'  => 'CK',
        'SP'  => '軍師・サポーター',
        'RP'  => '受付',
    ];

    public function index(Request $request)
    {
        // 対象月。既定＝今日の当月。?period=YYYY-MM が来ればその月（2026-09-07 baba要望）。
        // period は '2026-07' の形の月キー＝shift_preferences.period と同じ形。
        $month = $this->targetMonth($request);
        $period = $month->format('Y-m');
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        // 案件ID → date_type（本番のみ数えるため）。
        $projectType = Project::pluck('date_type', 'id');

        // 「希望数」＝その月に入れる枠の数。⚠ 数え方の正本は App\Support\WishCount 1か所だけ。
        //   ここで数え直すと、月まとめ自動アサインの充足率と食い違う（実際に食い違っていた）。
        $wishCount = WishCount::forMonth($period);

        // スタッフごとに一度だけ材料を引いておく（人数分のクエリを避ける）。
        $assignsByStaff = Assignment::where('status', '!=', 'キャンセル')->get()->groupBy('staff_id');
        $posByStaff = StaffRoleEligibility::all()->groupBy('staff_id');

        $people = Person::staff()->get()->map(function (Person $p) use (
            $monthStart, $monthEnd, $projectType, $assignsByStaff, $posByStaff, $wishCount
        ) {
            $mine = $wishCount[$p->id] ?? null;
            $want = (int) ($mine['wish'] ?? 0);

            // 1つも無い人はこの一覧の対象外（エントリーも〇も無い＝まだ何も言ってくれていない）。
            if ($want === 0) {
                return null;
            }

            $myAssigns = $assignsByStaff->get($p->id, collect());

            // 対象月・本番のアサインだけに絞る（割合・MC回数の母集団）。
            $monthAssigns = $myAssigns->filter(function (Assignment $a) use ($monthStart, $monthEnd, $projectType) {
                return $a->date
                    && $a->date->between($monthStart, $monthEnd)
                    && $projectType->get($a->project_id) === '本番';
            });

            $assigned = $monthAssigns->count();
            $mc = $monthAssigns->filter(fn (Assignment $a) => $a->role === 'MC')->count();

            // できるポジションを表示ラベルに直し、決まった順（D→受付）に並べる。
            $myPos = $posByStaff->get($p->id, collect())->pluck('position')->all();
            $pos = [];
            foreach (self::POS_LABELS as $key => $label) {
                if (in_array($key, $myPos, true)) {
                    $pos[] = $label;
                }
            }

            $total = (int) ($p->experience_count ?? 0);

            return [
                'id'       => $p->id,
                'name'     => $p->name,
                'lv'       => $total <= 10 ? 'new' : ($total < 30 ? 'mid' : 'vet'),
                'wish'     => $want,
                // 内訳（画面で「6（〇3日・応募1件）」と出すため）。
                // ⚠ 数字だけだと何を数えたのか分からない＝聞かれるたびに説明することになる。
                'okDays'   => (int) ($mine['okDays'] ?? 0),
                'entries'  => (int) ($mine['entries'] ?? 0),
                'assigned' => $assigned,
                'mc'       => $mc,
                'pos'      => $pos,
            ];
        })->filter()->values();

        return view('assign_wishlist', [
            'people' => $people,
            // 月の切替（画面の見出し・前後の月へのリンクに使う）。
            'period' => $period,
            'periodLabel' => $month->format('Y年n月'),
            'prevPeriod' => $month->copy()->subMonth()->format('Y-m'),
            'nextPeriod' => $month->copy()->addMonth()->format('Y-m'),
            'isThisMonth' => $period === Carbon::today()->format('Y-m'),
        ]);
    }

    /**
     * 見る月。?period=2026-10 が来ればその月、来なければ今月。
     * ⚠ 読めない形は今月として扱う（去年の数字が黙って出るのを防ぐ）。
     *   ここは /projects-agg の月切替と同じ考え方にそろえている。
     */
    private function targetMonth(Request $request): Carbon
    {
        $ym = (string) $request->query('period', '');
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $ym, $m)
            && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        }

        return Carbon::today()->startOfMonth();
    }
}
