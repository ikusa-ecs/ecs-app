<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * スタッフの稼働希望カレンダーに出す「その日のイベント◯件」（2026-10-07 スタッフ要望・baba決定）。
 *
 * スタッフの声：「募集段階で、その日に何件イベントがあるか分かると、アサインを予測して休みを調整しやすい」。
 * 希望を出すのはふつう公開より前なので、**公開していない案件も数える**。
 *
 * 【baba決定 2026-10-07】
 *  ・数えるのは確度が「確定」の案件だけ。Aヨミ・Bヨミ・Cヨミは数えない（流れることがあるため）。
 *    ⚠ 確度が空の案件は数える＝古い取込などで空のまま入っている本物の案件（新規登録は「確定」から始まる）。
 *  ・出すのは**件数だけ**。案件名・会社名・運営人数は出さない（未公開の中身・社内の数字だから）。
 *  ・自分の拠点の分だけ（ヘルプで来ている案件も含む＝募集タブと同じ OfficeScope::applyToProjects）。
 *
 * ⚠ 前日設営・リハ日も1件と数える（その日に人が要るのは同じため）。
 */
class StaffDayEventCount
{
    /**
     * @param  string  $period  対象月（YYYY-MM）
     * @param  string|null  $office  拠点（null＝拠点で絞らない）
     * @return array<int,int> 日（1〜31）=> 件数。0件の日は入れない。
     */
    public static function forMonth(string $period, ?string $office): array
    {
        [$y, $m] = array_map('intval', array_pad(explode('-', $period), 2, 1));
        $first = Carbon::create($y, $m, 1)->startOfDay();
        $last = $first->copy()->endOfMonth();

        $q = Project::query()
            ->notCancelled()
            // ⚠ status が空の案件も残す（「!= 下書き」だけだと SQL では空が落ちる）。
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', '下書き'))
            ->whereBetween('start_date', [$first->toDateString(), $last->toDateString()])
            ->where(fn ($w) => $w->whereNull('yomi')->orWhere('yomi', '')->orWhere('yomi', '確定'));
        OfficeScope::applyToProjects($q, $office);
        // 他の拠点に巻き取られた案件は、その拠点のスタッフが入る＝こちらでは数えない。
        OfficeScope::hideTakenOver($q, $office);

        $counts = [];
        foreach ($q->pluck('start_date') as $d) {
            $day = (int) Carbon::parse($d)->day;
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
