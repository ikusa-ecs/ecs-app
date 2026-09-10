<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * エントリー新着（来た順）の中身をつくる、ただ1か所（2026-09-10）。
 *
 * もとは `/entry-feed` という別画面だったが、サイドメニューが長くなってきたので
 * **エントリー一覧（/entries）の4つ目のタブ**に引っ越した（baba要望）。
 * 画面が2か所（タブと、古いURLを開いた人）から呼ばれるので、中身の作り方はここ1か所に置く。
 * ⚠ 数え方や並びを変えるときは、ここだけ直すこと（画面に書き写さない）。
 *
 * 何が見たい画面か：
 *   ・追加案件を出したあと、**誰から先に手が挙がったか**（早い者順で決めることがある）
 *   ・**新しく入った人**が、どの案件にエントリーしてくれたか
 *   ・エントリーはあるのに**稼働希望カレンダーではNG**という食い違い
 */
class EntryFeed
{
    /** 「新人」とみなす在籍月数（これ未満）。区分（skill_level）の新人＝1年未満と同じ考え方。 */
    private const NEWCOMER_MONTHS = 12;

    /** 期間の選択肢（0＝すべて）。画面のボタンもこれを使う。 */
    public const DAY_OPTIONS = [7 => '直近7日', 30 => '直近30日', 90 => '直近90日', 0 => 'すべて'];

    /** 期間の既定値。 */
    public const DEFAULT_DAYS = 30;

    /** 受け取った日数が選択肢に無ければ既定に戻す。 */
    public static function normalizeDays(mixed $days): int
    {
        $days = (int) $days;

        return array_key_exists($days, self::DAY_OPTIONS) ? $days : self::DEFAULT_DAYS;
    }

    /**
     * 画面に渡す一式を返す（rows／newCount／todoCount）。
     *
     * @param  string|null  $office  絞る拠点（null＝全拠点）。正本＝OfficeScope
     * @param  bool  $onlyExtra  追加案件のエントリーだけ
     * @param  bool  $onlyNew  新人のエントリーだけ
     * @param  int  $days  直近何日ぶんか（0＝すべて）
     * @return array{rows: Collection, newCount: int, todoCount: int}
     */
    public static function build(?string $office, bool $onlyExtra = false, bool $onlyNew = false, int $days = self::DEFAULT_DAYS): array
    {
        // 拠点で絞るのは「案件」だけ（応募は本人が手を挙げた記録なので、他拠点のスタッフでも出す）。
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->notCancelled()   // キャンセルになった案件は出さない（2026-08-26）
            ->get()
            ->keyBy('id');

        if ($projects->isEmpty()) {
            return self::pack(collect());
        }

        $apps = Application::whereIn('project_id', $projects->keys())
            ->when($days > 0, fn ($q) => $q->where(function ($qq) use ($days) {
                $from = Carbon::today()->subDays($days);
                $qq->where('applied_at', '>=', $from)->orWhere('created_at', '>=', $from);
            }))
            ->get();

        if ($apps->isEmpty()) {
            return self::pack(collect());
        }

        $people = Person::whereIn('id', $apps->pluck('staff_id')->unique())->get()->keyBy('id');
        $contentNames = Content::pluck('content_name', 'id');

        // その人が、その案件の日に「終日〇」を出しているか（2026-09-03 baba要望）。
        // ⚠ 出し方の正本は App\Support\ShiftWish（エントリー一覧の他のタブと同じもの）。
        $wishByKey = ShiftWish::forDays(
            $apps->pluck('staff_id')->unique()->all(),
            $projects->pluck('start_date')->filter()->map(fn ($d) => $d->format('Y-m-d'))->all()
        );

        // すでにアサイン済みか（キャンセル以外）＝「対応済み」の目印に使う。
        $assigned = Assignment::whereIn('project_id', $projects->keys())
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'status'])
            ->groupBy(fn ($a) => $a->project_id . '|' . $a->staff_id);

        $rows = $apps->map(function (Application $a) use ($projects, $people, $contentNames, $assigned, $wishByKey) {
            $p = $projects->get($a->project_id);
            $person = $people->get($a->staff_id);

            $firstContentId = is_array($p->content_ids ?? null) ? ($p->content_ids[0] ?? null) : null;
            $contentName = $firstContentId
                ? ($contentNames[$firstContentId] ?? $p->project_name)
                : $p->project_name;

            $when = $a->applied_at ?? $a->created_at;
            $key = $a->project_id . '|' . $a->staff_id;
            $status = optional($assigned->get($key))->firstWhere('status', '確定')
                ? '確定'
                : ($assigned->has($key) ? '仮' : null);

            // 稼働希望カレンダーの、その日の答え。'ok'＝終日〇／'ng'＝NG・希望休／null＝出していない。
            $wishCode = ShiftWish::of($wishByKey, $a->staff_id, $p->start_date?->format('Y-m-d'));

            return [
                'staffId'    => $a->staff_id,
                'staffName'  => $person->name ?? $a->staff_id,
                'wish'       => $wishCode,   // 終日〇を出しているか（2026-09-03 baba要望）
                'level'      => optional($person)->skill_level ?? '—',
                'isNew'      => self::isNewcomer($person),
                'entryCount' => 0,                     // あとで人ごとの件数を入れる
                'when'       => $when,
                'whenLabel'  => $when ? $when->format('n/j H:i') : '—',
                'intent'     => $a->intent ?? '希望',
                'note'       => (string) ($a->note ?? ''),
                'projectId'  => $a->project_id,
                'projectName' => $contentName ?: '（名称未定）',
                'client'     => $p->client ?? '',
                'date'       => $p->start_date ? $p->start_date->format('n/j') : '日付未定',
                'dow'        => $p->start_date ? ['日', '月', '火', '水', '木', '金', '土'][(int) $p->start_date->dayOfWeek] : '',
                'isExtra'    => ($p->category ?? '') === '追加案件',
                'office'     => $p->office ?? '',
                'published'  => (bool) $p->staff_published,
                'assignStatus' => $status,             // 確定 / 仮 / null（未対応）
            ];
        });

        // 人ごとのエントリー件数（この一覧の範囲で）＝「たくさん手を挙げてくれている人」が分かる。
        $countByStaff = $rows->countBy('staffId');
        $rows = $rows->map(function (array $r) use ($countByStaff) {
            $r['entryCount'] = $countByStaff[$r['staffId']] ?? 1;

            return $r;
        });

        // 絞り込み → 来た順（新しい順）
        $rows = $rows
            ->when($onlyExtra, fn ($c) => $c->where('isExtra', true))
            ->when($onlyNew, fn ($c) => $c->where('isNew', true))
            ->sortByDesc(fn ($r) => optional($r['when'])->timestamp ?? 0)
            ->values();

        return self::pack($rows);
    }

    /** 入社（登録）から1年未満＝新人。入社日が無い人は判定できないので新人にしない。 */
    private static function isNewcomer(?Person $person): bool
    {
        if (! $person || ! $person->hire_date) {
            return false;
        }

        return $person->hire_date->diffInMonths(Carbon::now()) < self::NEWCOMER_MONTHS;
    }

    /** @return array{rows: Collection, newCount: int, todoCount: int} */
    private static function pack(Collection $rows): array
    {
        return [
            'rows'      => $rows,
            'newCount'  => $rows->where('isNew', true)->count(),
            'todoCount' => $rows->whereNull('assignStatus')->count(),
        ];
    }
}
