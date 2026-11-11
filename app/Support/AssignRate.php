<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * アサイン率＝その月にエントリーした案件のうち、実際にアサインされた案件の割合（2026-11-11 小沼さん決定）。
 *
 * ・数えるのは「案件」の数（同じ案件に何日入っても1件）。
 * ・月＝案件の開催日（start_date）の月。キャンセルになった案件は数えない。
 * ・アサインはキャンセル以外（仮・確定）を「入れた」に数える＝「現状」の割合。
 * ・使う場所＝案件別アサイン（/assignment）の「アサイン率」列と、おすすめ点数の加点（低い人を上へ）。
 */
class AssignRate
{
    /** 加点の最大（アサイン率0%のとき）。100%なら0点。 */
    public const MAX_BONUS = 20;

    /**
     * @return array<string, array{entries:int, assigned:int, rate:float}> 本人ID => 件数と割合（エントリー0件の人は入らない）
     */
    public static function forMonth(Carbon $date): array
    {
        $from = $date->copy()->startOfMonth()->toDateString();
        $to = $date->copy()->endOfMonth()->toDateString();

        $pids = Project::whereBetween('start_date', [$from, $to])
            ->where(fn ($q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'))
            ->pluck('id');
        if ($pids->isEmpty()) {
            return [];
        }

        $entered = [];   // staff_id => [project_id => true]
        foreach (Application::whereIn('project_id', $pids)->get(['staff_id', 'project_id']) as $a) {
            $entered[(string) $a->staff_id][(string) $a->project_id] = true;
        }

        $assigned = [];
        foreach (Assignment::whereIn('project_id', $pids)->where('status', '!=', 'キャンセル')
            ->get(['staff_id', 'project_id']) as $a) {
            $assigned[(string) $a->staff_id][(string) $a->project_id] = true;
        }

        $out = [];
        foreach ($entered as $sid => $projects) {
            $e = count($projects);
            $m = count(array_intersect_key($assigned[$sid] ?? [], $projects));
            $out[$sid] = ['entries' => $e, 'assigned' => $m, 'rate' => $m / $e];
        }

        return $out;
    }

    /** 低いほど大きい加点（0%＝MAX_BONUS・100%＝0）。 */
    public static function bonus(float $rate): int
    {
        return (int) round(self::MAX_BONUS * (1 - max(0.0, min(1.0, $rate))));
    }

    /** 画面に出す文字（例：33%（2/6件））。 */
    public static function label(array $r): string
    {
        return round($r['rate'] * 100).'%（'.$r['assigned'].'/'.$r['entries'].'件）';
    }
}
