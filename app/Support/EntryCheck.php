<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectHistory;
use Carbon\Carbon;

/**
 * スタッフのエントリーが「別の案件」を指してしまっていないかの点検（2026-10-02 baba）。
 *
 * ⚠ 起きたこと：アサイン表の取込で案件のIDがずれ（9/30ごろ）、同じIDの中身が別の案件に
 *   書き換わった。エントリーは「スタッフ×案件ID」で持っているので、本人は11/8に出たつもりでも
 *   記録上は書き換わった先（例 11/15）にエントリーしていることになる。
 *
 * ここは **見るだけ**（データは一切変えない）。本人に確かめる相手を絞り込むためのもの。
 *  ① 書き換わり … エントリーした「あと」に、その案件の開催日・案件名・クライアント・コンテンツが変わった
 *  ② 同じ日にあとからできた案件 … エントリーした「あと」に、同じ日・同じ案件名（またはクライアント）の
 *     案件が別IDで作られた＝本人がそちらに付け直していない可能性
 *  ③ 消えた案件 … エントリー先の案件がもう無い
 *
 * エントリーの時刻＝applied_at（押し直すと更新される）。押し直していれば書き換わり後の中身を見て
 * 押しているので、対象から外れる。
 */
class EntryCheck
{
    /** 「別の案件になった」と言える項目だけ見る（時間・人数などは案件そのものは同じなので見ない）。 */
    public const FIELDS = ['start_date', 'project_name', 'client', 'content_ids', 'content_names'];

    /**
     * @return array{changed: list<array>, missing: list<array>, twins: list<array>}
     */
    public static function rows(Carbon $since): array
    {
        $apps = Application::query()->orderBy('project_id')->get();
        if ($apps->isEmpty()) {
            return ['changed' => [], 'missing' => [], 'twins' => []];
        }

        $projectIds = $apps->pluck('project_id')->unique()->values()->all();
        $projects = Project::whereIn('id', $projectIds)->get()->keyBy(fn ($p) => (string) $p->id);
        $names = Person::whereIn('id', $apps->pluck('staff_id')->unique()->all())->pluck('name', 'id');

        $histories = ProjectHistory::whereIn('project_id', $projectIds)
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get()
            ->groupBy('project_id');

        $changed = [];
        $missing = [];
        $twins = [];

        foreach ($apps as $a) {
            $pid = (string) $a->project_id;
            $at = $a->applied_at ?? $a->updated_at ?? $a->created_at;
            $base = [
                'staff_id'   => $a->staff_id,
                'staff_name' => $names[$a->staff_id] ?? '（名簿にいない人）',
                'project_id' => $pid,
                'applied_at' => $at?->format('Y-m-d H:i'),
            ];
            $p = $projects->get($pid);

            if (! $p) {
                // 消えた案件。最後の名前は履歴から拾う（消した記録が残っていれば）。
                $last = ProjectHistory::where('project_id', $pid)->orderByDesc('id')->first();
                $missing[] = $base + ['project_name' => $last?->project_name ?? '（記録なし）'];

                continue;
            }

            $base += [
                'project_name' => $p->project_name,
                'start_date'   => $p->start_date?->format('Y-m-d'),
                'client'       => $p->client,
            ];

            // ① エントリーしたあとに書き換わった項目
            $diffs = [];
            foreach ($histories->get($pid, collect()) as $h) {
                if ($h->action !== 'updated' || ! in_array($h->field, self::FIELDS, true)) {
                    continue;
                }
                if ($at && $h->created_at && $h->created_at->lessThanOrEqualTo($at)) {
                    continue;   // エントリーより前の変更＝本人は変わったあとを見て押している
                }
                $diffs[] = [
                    'label' => $h->field_label ?? $h->field,
                    'old'   => (string) $h->old_value,
                    'new'   => (string) $h->new_value,
                    'at'    => $h->created_at?->format('Y-m-d H:i'),
                    'by'    => $h->person_name ?: '（取込・自動）',
                ];
            }
            if ($diffs !== []) {
                $changed[] = $base + ['diffs' => $diffs];
            }

            // ③ 同じ日に、あとから別IDでできた同じ案件
            if ($p->start_date) {
                $later = Project::query()
                    ->where('id', '!=', $pid)
                    ->whereDate('start_date', $p->start_date->format('Y-m-d'))
                    ->where('created_at', '>=', $since)
                    ->when($at, fn ($q) => $q->where('created_at', '>', $at))
                    ->get()
                    ->filter(fn ($o) => self::same($o->project_name, $p->project_name)
                        || ($p->client && self::same($o->client, $p->client)));
                foreach ($later as $o) {
                    $already = $apps->first(fn ($x) => $x->staff_id === $a->staff_id && (string) $x->project_id === (string) $o->id);
                    if ($already) {
                        continue;   // 新しい方にもエントリーしている＝本人は気づいている
                    }
                    $twins[] = $base + [
                        'twin_id'      => (string) $o->id,
                        'twin_name'    => $o->project_name,
                        'twin_created' => $o->created_at?->format('Y-m-d H:i'),
                    ];
                }
            }
        }

        $sort = fn ($x, $y) => [$x['start_date'] ?? '', $x['project_id'], $x['staff_name']]
            <=> [$y['start_date'] ?? '', $y['project_id'], $y['staff_name']];
        usort($changed, $sort);
        usort($twins, $sort);

        return ['changed' => $changed, 'missing' => $missing, 'twins' => $twins];
    }

    /** 書き方の違い（空白・全角半角）を無視して同じ名前か。 */
    private static function same(?string $a, ?string $b): bool
    {
        $n = fn ($s) => preg_replace('/\s+/u', '', mb_convert_kana((string) $s, 'asKV'));

        return $n($a) !== '' && $n($a) === $n($b);
    }
}
