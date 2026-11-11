<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;

/**
 * アサイン率＝その月に「出られる」と示した日のうち、実際にアサインされた日の割合（2026-11-11 小沼さん決定）。
 *
 * ・出られる日＝エントリーした案件の開催日 ＋ カレンダーで〇（希望・稼働可）にした日（重なりは1日）。
 *   ⚠ はじめはエントリーした案件だけで数えていたが、カレンダーで〇をたくさん付けた人が「100%（1/1件）」になった（同日 小沼さん指摘）。
 * ・入れた日＝キャンセル以外（仮・確定）のアサインがある日。キャンセルになった案件は数えない。
 *   出られる日でない日に入れた分も分母に足す（100%を超えないように）。
 * ・使う場所＝案件別アサイン（/project-assign）の「アサイン率」列と、おすすめ点数の加点（低い人を上へ）。
 */
class AssignRate
{
    /** 加点の最大（アサイン率0%のとき）。100%なら0点。 */
    public const MAX_BONUS = 20;

    /**
     * @return array<string, array{entries:int, assigned:int, rate:float}> 本人ID => 出られる日数・入れた日数・割合（どちらも0の人は入らない）
     */
    public static function forMonth(Carbon $date): array
    {
        $from = $date->copy()->startOfMonth()->toDateString();
        $to = $date->copy()->endOfMonth()->toDateString();

        $live = Project::whereBetween('start_date', [$from, $to])
            ->where(fn ($q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'))
            ->pluck('start_date', 'id');

        $offer = [];    // staff_id => [Y-m-d => true]
        foreach (Application::whereIn('project_id', $live->keys())->get(['staff_id', 'project_id']) as $a) {
            $d = $live[$a->project_id] ?? null;
            if ($d) {
                $offer[(string) $a->staff_id][Carbon::parse($d)->toDateString()] = true;
            }
        }
        foreach (ShiftPreference::available()->whereBetween('date', [$from, $to])->get(['staff_id', 'date']) as $w) {
            $offer[(string) $w->staff_id][$w->date->toDateString()] = true;
        }

        $done = [];
        foreach (Assignment::whereBetween('date', [$from, $to])->where('status', '!=', 'キャンセル')
            ->onLiveProject()->get(['staff_id', 'date']) as $a) {
            $done[(string) $a->staff_id][$a->date->toDateString()] = true;
        }

        $out = [];
        foreach (array_unique(array_merge(array_keys($offer), array_keys($done))) as $sid) {
            $o = $offer[$sid] ?? [];
            $m = $done[$sid] ?? [];
            $e = count($o + $m);
            if ($e === 0) {
                continue;
            }
            $out[(string) $sid] = ['entries' => $e, 'assigned' => count($m), 'rate' => count($m) / $e];
        }

        return $out;
    }

    /** 低いほど大きい加点（0%＝MAX_BONUS・100%＝0）。 */
    public static function bonus(float $rate): int
    {
        return (int) round(self::MAX_BONUS * (1 - max(0.0, min(1.0, $rate))));
    }

    /** 画面に出す文字（例：10%（1/10日））。 */
    public static function label(array $r): string
    {
        return round($r['rate'] * 100).'%（'.$r['assigned'].'/'.$r['entries'].'日）';
    }
}
