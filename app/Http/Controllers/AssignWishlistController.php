<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use App\Models\StaffRoleEligibility;
use Illuminate\Support\Carbon;

/**
 * 希望まとめ（/assign-wishlist・別ウィンドウ）。
 *
 * いま稼働希望を出しているスタッフ（対象月＝今日の当月。例：7月に開けば2026-07）の一覧を DB から作る。
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

    public function index()
    {
        // 対象月＝今日の当月（当月の1日〜末日）。period は '2026-07' の形の月キー。
        $period = Carbon::today()->format('Y-m');
        $monthStart = Carbon::today()->startOfMonth();
        $monthEnd = Carbon::today()->endOfMonth();

        // 案件ID → date_type（本番のみ数えるため）。
        $projectType = Project::pluck('date_type', 'id');

        // スタッフごとに一度だけ材料を引いておく（人数分のクエリを避ける）。
        $prefByStaff = ShiftPreference::where('period', $period)->available()->get()->groupBy('staff_id');
        $assignsByStaff = Assignment::where('status', '!=', 'キャンセル')->get()->groupBy('staff_id');
        $posByStaff = StaffRoleEligibility::all()->groupBy('staff_id');

        $people = Person::staff()->get()->map(function (Person $p) use ($monthStart, $monthEnd, $projectType, $prefByStaff, $assignsByStaff, $posByStaff) {
            // 希望日数（対象月）。0件の人は対象外なので後で除く。
            $want = $prefByStaff->get($p->id, collect())->count();
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
                'assigned' => $assigned,
                'mc'       => $mc,
                'pos'      => $pos,
            ];
        })->filter()->values();

        return view('assign_wishlist', ['people' => $people]);
    }
}
