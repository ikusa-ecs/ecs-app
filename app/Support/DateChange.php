<?php

namespace App\Support;

use App\Models\ProjectHistory;
use Illuminate\Support\Carbon;

/**
 * 「日付が変わった案件」の印（2026-10-26 baba要望）。
 *
 * ・正本＝編集履歴の「開催日」の変更（取り込みでも手入力でも同じ記録が残る）。
 * ・見せる期間＝変わってから7日間（それを過ぎたら自動で消える）。
 * ・ECSの画面もスタッフの画面も、この決まりを通す（画面ごとに判定し直さない）。
 */
final class DateChange
{
    /** 印を見せる日数。 */
    public const DAYS = 7;

    /**
     * 案件ごとの「直近の日付変更」。['from' => 旧（n/j形式）, 'to' => 新（n/j形式）, 'at' => 変わった日時]
     *
     * @param  list<string>  $projectIds
     * @return array<string, array{from: string, to: string, at: string}>
     */
    public static function forProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds, fn ($id) => (string) $id !== '')));
        if ($projectIds === []) {
            return [];
        }

        $since = Carbon::now()->subDays(self::DAYS);
        $rows = ProjectHistory::query()
            ->whereIn('project_id', $projectIds)
            ->where('action', 'updated')
            ->where('field', 'start_date')
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->get(['project_id', 'old_value', 'new_value', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $pid = (string) $r->project_id;
            if (isset($out[$pid])) {
                continue;   // 新しい順に読んでいるので、最初の1件（直近）だけ使う
            }
            $from = self::short((string) $r->old_value);
            $to = self::short((string) $r->new_value);
            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }
            $out[$pid] = ['from' => $from, 'to' => $to, 'at' => (string) $r->created_at];
        }

        return $out;
    }

    /** 2026-11-20 → 11/20（画面に出す短い形）。読めなければ空。 */
    private static function short(string $value): string
    {
        // 履歴の日付は「2026/11/20」の形で入っている（ProjectFieldLabels::dateText）。
        $value = trim($value);
        if (! preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})#', $value, $m)) {
            return '';
        }

        return ((int) $m[2]).'/'.((int) $m[3]);
    }
}
