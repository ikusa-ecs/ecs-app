<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\OfficeScope;
use App\Support\ShiftWish;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * エントリー新着（/entry-feed）。2026-08-21 baba要望。
 *
 * 「エントリー一覧（/entries）」は案件ごとの一覧なので、"いつ・誰から来たか" が追えなかった。
 * この画面は**来た順（新しい順）**に並べる。とくに知りたいのは：
 *   ・追加案件を出したあと、誰から手が挙がったか（早い者順で決めることがある）
 *   ・新しく入った人（新人）が、どの案件にエントリーしてくれたか
 *
 * 見るだけの画面（保存はしない）。アサインは各案件のアサイン画面へリンクで飛ぶ。
 */
class EntryFeedController extends Controller
{
    /** 「新人」とみなす在籍月数（これ未満）。区分（skill_level）の新人＝1年未満と同じ考え方。 */
    private const NEWCOMER_MONTHS = 12;

    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);

        // 絞り込み（画面のプルダウン）。extra=追加案件のみ／new=新人のみ／days=直近何日か。
        $onlyExtra = $request->boolean('extra');
        $onlyNew   = $request->boolean('new');
        $days      = (int) $request->query('days', 30);
        if (! in_array($days, [7, 30, 90, 0], true)) {
            $days = 30;
        }

        // 拠点で絞るのは「案件」だけ（応募は本人が手を挙げた記録なので、他拠点のスタッフでも出す）。
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->notCancelled()   // キャンセルになった案件は出さない（2026-08-26）
            ->get()
            ->keyBy('id');

        if ($projects->isEmpty()) {
            return view('entry_feed', $this->viewData(collect(), $office, $onlyExtra, $onlyNew, $days));
        }

        $apps = Application::whereIn('project_id', $projects->keys())
            ->when($days > 0, fn ($q) => $q->where(function ($qq) use ($days) {
                $from = Carbon::today()->subDays($days);
                $qq->where('applied_at', '>=', $from)->orWhere('created_at', '>=', $from);
            }))
            ->get();

        if ($apps->isEmpty()) {
            return view('entry_feed', $this->viewData(collect(), $office, $onlyExtra, $onlyNew, $days));
        }

        $people = Person::whereIn('id', $apps->pluck('staff_id')->unique())->get()->keyBy('id');
        $contentNames = Content::pluck('content_name', 'id');

        // その人が、その案件の日に「終日〇」を出しているか（2026-09-03 baba要望）。
        // ⚠ 出し方の正本は App\Support\ShiftWish（エントリー一覧 /entries と同じもの）。
        //   画面ごとに書くと、片方だけ直して食い違う。
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
                'isNew'      => $this->isNewcomer($person),
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

        return view('entry_feed', $this->viewData($rows, $office, $onlyExtra, $onlyNew, $days));
    }

    /** 入社（登録）から1年未満＝新人。入社日が無い人は判定できないので新人にしない。 */
    private function isNewcomer(?Person $person): bool
    {
        if (! $person || ! $person->hire_date) {
            return false;
        }

        return $person->hire_date->diffInMonths(Carbon::now()) < self::NEWCOMER_MONTHS;
    }

    private function viewData($rows, ?string $office, bool $onlyExtra, bool $onlyNew, int $days): array
    {
        return [
            'rows'        => $rows,
            'officeScope' => $office,
            'onlyExtra'   => $onlyExtra,
            'onlyNew'     => $onlyNew,
            'days'        => $days,
            'newCount'    => collect($rows)->where('isNew', true)->count(),
            'todoCount'   => collect($rows)->whereNull('assignStatus')->count(),
        ];
    }
}
