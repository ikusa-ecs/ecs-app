<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ShiftPreference;

/**
 * 前泊・後泊の案件で「前日・翌日が×の人」を見つける正本（2026-10-14・FBシート No.23 桑江さん）。
 *
 * 【なぜ要るか】
 * ⚠ 稼働希望（カレンダー）の〇×は**開催日だけ**で判定していた。前泊の案件なのに前日が×の人、
 *   後泊の案件なのに翌日が×の人も「稼働可」として候補に出て、自動アサインでも選ばれていた。
 *
 * 【決めたこと（baba・A案）】
 *   ・前泊＝前日、後泊＝翌日も見る。その日が NG／希望休なら「⚠ 前日10/24が×」の札。
 *   ・候補からは**外さない**（事情を聞いて頼むこともある）。自動アサインでは選ばない。
 *   ・未定（△）・未入力は札を付けない（休みとは言っていないため）。
 *   ・新人ページのFCの案（RookieFcPlan）は前から前日も見ている＝そちらはそのまま。
 */
final class StayDayCheck
{
    /**
     * この案件で見る日。[['label' => '前日', 'date' => 'Y-m-d'], ...]。前泊・後泊が無ければ空。
     *
     * @return list<array{label: string, date: string}>
     */
    public static function days(Project $p): array
    {
        if (! $p->start_date) {
            return [];
        }
        $out = [];
        if (Lodging::hasPreStay($p->lodging)) {
            $out[] = ['label' => '前日', 'date' => $p->start_date->copy()->subDay()->format('Y-m-d')];
        }
        if (Lodging::hasPostStay($p->lodging)) {
            $out[] = ['label' => '翌日', 'date' => $p->start_date->copy()->addDay()->format('Y-m-d')];
        }

        return $out;
    }

    /**
     * 案件ごと・人ごとの札。返り値＝ [案件ID => [本人ID => '前日10/24が×（前泊）']]。札の無い人は入らない。
     *
     * @param  iterable<Project>  $projects
     * @param  array<int, string>|null  $staffIds  null＝×を出している人ぜんぶ
     * @return array<string, array<string, string>>
     */
    public static function map(iterable $projects, ?array $staffIds = null): array
    {
        $need = [];
        $days = [];
        foreach ($projects as $p) {
            $d = self::days($p);
            if ($d) {
                $need[$p->id] = $d;
                foreach ($d as $x) {
                    $days[] = $x['date'];
                }
            }
        }
        if (! $need) {
            return [];
        }
        // null＝その日に×を出している人ぜんぶ（日別ボードのように、見る人を先に決めにくい画面用）。
        if ($staffIds === null) {
            $staffIds = ShiftPreference::whereBetween('date', [min($days).' 00:00:00', max($days).' 23:59:59'])
                ->whereIn('availability', ['NG', '希望休'])->distinct()->pluck('staff_id')->all();
        }
        if (! $staffIds) {
            return [];
        }

        $wish = ShiftWish::forDays($staffIds, $days);
        $out = [];
        foreach ($need as $pid => $d) {
            foreach ($staffIds as $sid) {
                $ng = [];
                foreach ($d as $x) {
                    if (($wish[$sid.'|'.$x['date']] ?? null) === 'ng') {
                        $ng[] = $x['label'].date('n/j', strtotime($x['date']));
                    }
                }
                if ($ng) {
                    $out[$pid][$sid] = implode('・', $ng).'が×（'.(count($ng) === 2 ? '前後泊' : (str_starts_with($ng[0], '前日') ? '前泊' : '後泊')).'）';
                }
            }
        }

        return $out;
    }
}
