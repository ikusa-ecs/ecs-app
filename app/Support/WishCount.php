<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;

/**
 * 「希望数」の正本（2026-09-07 baba決定）。
 *
 * 【希望数とは】その月に、その人が **入れる枠の数**。
 *   ・その日に「〇（稼働可）」を出している    → **その日の案件数**（案件が無ければ 1）
 *   ・〇は出していないがエントリーがある日     → **その日にエントリーした件数**
 *   ・どちらも無い日                          → 0
 *
 * ⚠ **同じ日を二重に数えない。** 〇の日のエントリーは「その日の案件数」にすでに含まれる。
 * ⚠ 〇の日を「案件数」で数えるのは、その日は**その日の案件どれにでも入れる**という意味だから。
 *   案件が無い日は入りようがないので 1 で置く（「その日を空けてくれている」重みだけ残す）。
 *
 * 【なぜ1か所にまとめたか】
 * はじめ「スタッフ一覧（/assign-wishlist）」と「月まとめ自動アサイン」で
 * **別々に数えていた**（前者は上のとおり／後者は単に〇の日数）。
 * 同じ「希望数」「充足率」という言葉なのに画面で数が違う＝どちらが正しいのか分からなくなる。
 * ⚠ 数え方を変えるときは**このファイルだけ**を直すこと。
 */
final class WishCount
{
    /** 〇（入れる）とみなす稼働希望の値。 */
    private const OK = ['稼働可', '希望'];

    /**
     * その月の希望数を人ごとに数える。
     *
     * @param  string  $period  'YYYY-MM'
     * @param  array<int, string>  $staffIds  空なら全員
     * @return array<string, array{wish:int, okDays:int, entries:int}>
     */
    public static function forMonth(string $period, array $staffIds = []): array
    {
        [$y, $m] = array_map('intval', explode('-', $period));
        $start = Carbon::create($y, $m, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $from = $start->format('Y-m-d').' 00:00:00';
        $to = $end->format('Y-m-d').' 23:59:59';

        // その月の案件（下書き・キャンセル・片付け済みは数えない）。
        $projectsPerDay = [];   // 'Y-m-d' => その日の案件数
        $dayOfProject = [];     // project_id => 'Y-m-d'
        $projects = Project::whereNotNull('start_date')
            ->whereBetween('start_date', [$from, $to])
            ->notCancelled()
            ->get(['id', 'start_date', 'status', 'is_archived'])
            ->filter(fn (Project $p) => $p->status !== '下書き' && $p->is_archived !== true);
        foreach ($projects as $p) {
            $d = $p->start_date->format('Y-m-d');
            $projectsPerDay[$d] = ($projectsPerDay[$d] ?? 0) + 1;
            $dayOfProject[$p->id] = $d;
        }

        // 〇を出した日（人ごと）。
        $okDays = [];
        ShiftPreference::query()
            ->whereBetween('date', [$from, $to])
            ->when($staffIds, fn ($q) => $q->whereIn('staff_id', $staffIds))
            ->get(['staff_id', 'date', 'availability'])
            ->each(function ($sp) use (&$okDays) {
                if (! in_array((string) $sp->availability, self::OK, true) || ! $sp->date) {
                    return;
                }
                $okDays[$sp->staff_id][$sp->date->format('Y-m-d')] = true;
            });

        // エントリー（人ごと・日ごとの件数）。
        $entryDays = [];
        $entryTotal = [];
        if ($dayOfProject) {
            Application::whereIn('project_id', array_keys($dayOfProject))
                ->when($staffIds, fn ($q) => $q->whereIn('staff_id', $staffIds))
                ->get(['project_id', 'staff_id'])
                ->each(function ($a) use (&$entryDays, &$entryTotal, $dayOfProject) {
                    $d = $dayOfProject[$a->project_id];
                    $entryDays[$a->staff_id][$d] = ($entryDays[$a->staff_id][$d] ?? 0) + 1;
                    $entryTotal[$a->staff_id] = ($entryTotal[$a->staff_id] ?? 0) + 1;
                });
        }

        $out = [];
        foreach (array_unique(array_merge(array_keys($okDays), array_keys($entryDays))) as $sid) {
            $mine = $okDays[$sid] ?? [];
            $wish = 0;
            foreach (array_keys($mine) as $d) {
                $wish += max(1, (int) ($projectsPerDay[$d] ?? 0));
            }
            foreach (($entryDays[$sid] ?? []) as $d => $n) {
                if (! isset($mine[$d])) {
                    $wish += (int) $n;   // 〇は出していないがエントリーした日
                }
            }
            $out[$sid] = [
                'wish' => $wish,
                'okDays' => count($mine),
                'entries' => (int) ($entryTotal[$sid] ?? 0),
            ];
        }

        return $out;
    }
}
