<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * 案件の開催日を変えたら、その案件のアサインの日付も一緒に新しい日へ移す（2026-10-09 baba了承）。
 *
 * 【なぜ要るか】
 * アサイン（assignments）は「案件 × 人 × 日」で持つ。開催日を変えてもアサインの日付は古いまま残り、
 *   ・D決めは今の開催日の行しか見ない＝Dが空に見える／入れ直すと古い日のDが取り残される
 *   ・アサイン表（日付を見ない）には古いDが残る
 *   ・出勤数が古い日付で数えられる
 * が起きていた（2026-10-05 に発見。10/9「エントリーを募ったあとで日程が違うと分かった」案件で必要になった）。
 *
 * 【動かすもの】
 * ⚠ **古い開催日と同じ日付の行だけ**を動かす。予備日・リハなど別の日の行には触らない。
 * ⚠ 新しい日にもう同じ人の行があれば、古い行は消す（同じ人が同じ日に2行にならないように）。
 * 入口は Project の updated だけ＝開催日を変える画面がいくつあっても、ここ1か所で効く。
 */
final class AssignmentDateFollow
{
    /** @return int 動かした（または重なって消した）行の数 */
    public static function follow(Project $project): int
    {
        if (! $project->wasChanged('start_date')) {
            return 0;
        }

        $old = self::day($project->getOriginal('start_date'));
        $new = self::day($project->start_date);
        if ($old === null || $new === null || $old === $new) {
            return 0;
        }

        $moved = 0;
        $rows = Assignment::where('project_id', $project->id)->whereDate('date', $old)->get();
        foreach ($rows as $row) {
            $already = Assignment::where('project_id', $project->id)
                ->where('staff_id', $row->staff_id)
                ->whereDate('date', $new)
                ->exists();
            if ($already) {
                $row->delete();
            } else {
                $row->date = $new;
                $row->save();
            }
            $moved++;
        }

        return $moved;
    }

    private static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
