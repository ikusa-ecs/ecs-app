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
 * ・画面ごとに判定し直さない。日別ボード・公開ボード・スタッフのエントリー画面はここを通す。
 * ・名前を出すのは社員・管理者の画面だけ（スタッフの画面は「前回もアサインされた」かどうかだけ）。
 */
final class RepeatHistory
{
    /**
     * リピートの案件ごとの「前回」。リピートでない案件・前回が無い案件は含めない。
     *
     * @param  Collection<int, Project>|iterable<Project>  $projects
     * @return array<string, array{id: string, name: string, date: string, staffIds: list<string>, staffNames: list<string>}>
     */
    public static function forProjects($projects, bool $withNames = true): array
    {
        $targets = collect($projects)->filter(fn (Project $p) => $p->is_repeat && trim((string) $p->client) !== '' && $p->start_date);
        if ($targets->isEmpty()) {
            return [];
        }

        $clients = $targets->pluck('client')->map(fn ($c) => (string) $c)->unique()->values()->all();
        $candidates = Project::query()
            ->whereIn('client', $clients)
            ->whereNotNull('start_date')
            ->where('is_cancelled', false)
            ->get(['id', 'client', 'project_name', 'start_date']);

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
            $prev = $candidates
                ->filter(fn (Project $c) => (string) $c->client === (string) $p->client
                    && (string) $c->id !== (string) $p->id
                    && Carbon::parse($c->start_date)->startOfDay()->lt($start)
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
                'date' => Carbon::parse($prev->start_date)->format('n/j'),
                'staffIds' => $ids,
                'staffNames' => $withNames ? array_values(array_filter(array_map(fn ($i) => $names[$i] ?? null, $ids))) : [],
            ];
        }

        return $out;
    }
}
