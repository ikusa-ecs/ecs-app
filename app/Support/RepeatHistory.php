<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * リピート案件の「前回」（2026-10-26 baba要望）。
 *
 * ・「前回」＝同じクライアントの、開催日がこの案件より前の案件のうち、いちばん近い1件
 *   （キャンセルを除き、アサインが1人以上いるもの）。自動アサインの「リピート継続」と同じクライアントの決まり。
 * ・画面ごとに判定し直さない。日別ボード・公開ボード・スタッフのエントリー画面・エントリー一覧・
 *   おすすめの点数（個別アサイン・月まとめ自動アサイン）はここを通す。
 * ・名前を出すのは社員・管理者の画面だけ（スタッフの画面は「前回もアサインされた」かどうかだけ）。
 *
 * 2026-11-16 小沼さん決定：**「リピート案件」のチェックが無くても、前回があればリピートとして扱う。**
 *   アサイン表から取り込んだ案件にはチェックが付かず、前回の人が分からなかったため。
 *   ⚠ 前回に数えないもの（数えると、ただの連日・同じ月の別案件が「リピート」に見える）：
 *     ・同じ連日イベントの別の日（parent_project_id で結ばれた日）
 *     ・同じ月の案件（11/28 と 11/29 が同じお客様、など）
 *     ・お客様の名前が決まっていない案件（「顧客名なし」「未定」など＝別のお客様どうしがつながってしまう）
 */
final class RepeatHistory
{
    /**
     * 案件ごとの「前回」。前回が無い案件は含めない（＝含まれていればリピート）。
     *
     * @param  Collection<int, Project>|iterable<Project>  $projects
     * @return array<string, array{id: string, name: string, date: string, staffIds: list<string>, staffNames: list<string>}>
     */
    public static function forProjects($projects, bool $withNames = true): array
    {
        $targets = collect($projects)->filter(fn (Project $p) => ! ClientName::isUnknown($p->client) && $p->start_date);
        if ($targets->isEmpty()) {
            return [];
        }

        $clients = $targets->pluck('client')->map(fn ($c) => (string) $c)->unique()->values()->all();
        $latest = $targets->map(fn (Project $p) => Carbon::parse($p->start_date)->format('Y-m-d'))->max();
        $candidates = Project::query()
            ->whereIn('client', $clients)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<', $latest)
            ->where('is_cancelled', false)
            ->get(['id', 'client', 'project_name', 'start_date', 'parent_project_id']);
        if ($candidates->isEmpty()) {
            return [];
        }

        $withStaff = Assignment::query()
            ->whereIn('project_id', $candidates->pluck('id'))
            ->where('status', '!=', 'キャンセル')
            ->onLiveProject()
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id');

        $names = $withNames && $withStaff->isNotEmpty()
            ? Person::whereIn('id', $withStaff->flatten(1)->pluck('staff_id')->unique())->pluck('name', 'id')
            : collect();

        $out = [];
        foreach ($targets as $p) {
            $start = Carbon::parse($p->start_date)->startOfDay();
            $month = $start->format('Y-m');
            $root = ProjectSeries::rootId($p);
            $prev = $candidates
                ->filter(fn (Project $c) => (string) $c->client === (string) $p->client
                    && (string) $c->id !== (string) $p->id
                    && Carbon::parse($c->start_date)->startOfDay()->lt($start)
                    && Carbon::parse($c->start_date)->format('Y-m') !== $month   // 同じ月は前回にしない
                    && ProjectSeries::rootId($c) !== $root                        // 連日イベントの別の日は前回にしない
                    && $withStaff->has($c->id))
                ->sortByDesc(fn (Project $c) => Carbon::parse($c->start_date)->timestamp)
                ->first();
            if (! $prev) {
                continue;
            }
            $ids = $withStaff[$prev->id]->pluck('staff_id')->unique()->values()->map(fn ($i) => (string) $i)->all();
            $out[(string) $p->id] = [
                'id' => (string) $prev->id,
                'name' => (string) ($prev->project_name ?? ''),
                // 年がちがうときは年も出す（「去年の12/5」が今年の日付に見えないように）。
                'date' => Carbon::parse($prev->start_date)->format(
                    Carbon::parse($prev->start_date)->year === $start->year ? 'n/j' : 'Y/n/j'
                ),
                'staffIds' => $ids,
                'staffNames' => $withNames ? array_values(array_filter(array_map(fn ($i) => $names[$i] ?? null, $ids))) : [],
            ];
        }

        return $out;
    }

    /**
     * 1案件ぶんの「前回入った人」。おすすめの点数（AssignmentScorer）に渡す。
     *
     * @return list<string>
     */
    public static function staffIdsFor(Project $p): array
    {
        return self::forProjects([$p], false)[(string) $p->id]['staffIds'] ?? [];
    }
}
