<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\AssignmentRole;
use App\Support\DispatchRows;
use App\Support\OfficeScope;
use App\Support\ProjectContentName;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 過去案件（/past-projects）。2026-10-09 baba要望。
 *
 * 【なぜ作ったか】
 * 過去のアサイン表（2023年〜）を取り込むと、案件一覧のアーカイブや公開ボードが
 * 「全部の案件を毎回読む」作りのため重くなる。そこで過去は**この画面で月ごとに見る**。
 *
 * 【決めたこと（2026-10-09 baba）】
 *  ・左に年のフォルダ → 開くと月 → 月を選ぶとその月の案件とメンバー。
 *  ・**見るだけ**（直す入口は置かない）。見られるのは社員以上（ルートの tier:employee）。
 *  ・データは今までと同じ projects / assignments＝会社名・経験回数・集計はそのまま反映される。
 *    ⚠ 別の表に移さない（経験回数 ExperienceCount は assignments から数えている）。
 *
 * ⚠ 重くしないために、案件の中身は**選んだ1か月ぶんだけ**読む。
 *   フォルダの件数は開催日だけを読んで数える（中身は読まない）。
 */
class PastProjectsController extends Controller
{
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);
        $today = Carbon::today();

        // 過去＝開催日が今日より前。下書きは実際に行っていないので出さない。
        $base = fn () => OfficeScope::applyToProjects(Project::query(), $office)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<', $today->format('Y-m-d'))
            ->where(fn ($q) => $q->where('status', '!=', '下書き')->orWhereNull('status'));

        // ① フォルダ（年 → 月 → 件数）。開催日だけ読む。
        $counts = [];
        foreach ($base()->pluck('start_date') as $d) {
            $d = Carbon::parse($d);
            $counts[$d->year][$d->month] = ($counts[$d->year][$d->month] ?? 0) + 1;
        }
        krsort($counts);
        $years = [];
        foreach ($counts as $y => $months) {
            krsort($months);
            $years[] = [
                'year' => $y,
                'total' => array_sum($months),
                'months' => collect($months)->map(fn ($n, $m) => ['month' => $m, 'count' => $n, 'ym' => sprintf('%04d-%02d', $y, $m)])->values()->all(),
            ];
        }

        // ② 開く月。?ym=2025-07。無い・読めないときは、案件があるいちばん新しい月。
        $ym = (string) $request->query('ym', '');
        if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) || ! isset($counts[(int) $m[1]][(int) $m[2]])) {
            $ym = $years ? $years[0]['months'][0]['ym'] : '';
        }

        $cases = $ym === '' ? [] : $this->casesOf($base(), $ym);

        return view('past_projects', [
            'years' => $years,
            'ym' => $ym,
            'ymLabel' => $ym === '' ? '' : Carbon::parse($ym.'-01')->format('Y年n月'),
            'cases' => $cases,
            'officeScope' => $office,
            'officeParam' => OfficeScope::param($office),
        ]);
    }

    /**
     * 1か月ぶんの案件を、画面に出す形にする。
     *
     * @return list<array<string, mixed>>
     */
    private function casesOf($query, string $ym): array
    {
        $from = Carbon::parse($ym.'-01');
        $projects = $query
            ->whereDate('start_date', '>=', $from->format('Y-m-d'))
            ->whereDate('start_date', '<=', $from->copy()->endOfMonth()->format('Y-m-d'))
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->get();

        if ($projects->isEmpty()) {
            return [];
        }

        $ids = $projects->pluck('id')->all();
        $contentNames = Content::pluck('content_name', 'id');

        // メンバー＝キャンセル以外。複数日の案件でも1人1回にまとめる（同じ人の日ごとの行を重ねない）。
        $assignments = Assignment::whereIn('project_id', $ids)
            ->where('status', '!=', 'キャンセル')
            ->orderBy('date')
            ->get(['project_id', 'staff_id', 'role', 'status']);
        $names = Person::whereIn('id', $assignments->pluck('staff_id')->unique()->filter()->all())
            ->pluck('name', 'id');
        $roleOrder = array_flip(array_keys(AssignmentRole::LABELS));

        $dispatches = DispatchRows::forProjects($ids);

        return $projects->map(function (Project $p) use ($assignments, $names, $roleOrder, $contentNames, $dispatches) {
            $members = [];
            foreach ($assignments->where('project_id', $p->id) as $a) {
                $sid = (string) $a->staff_id;
                if ($sid === '' || isset($members[$sid])) {
                    // 同じ人が複数日に入っていれば、確定が1日でもあれば確定として出す。
                    if ($sid !== '' && $a->status === '確定') {
                        $members[$sid]['tentative'] = false;
                    }

                    continue;
                }
                $members[$sid] = [
                    'name' => $names[$sid] ?? $sid,
                    'role' => $a->role ? AssignmentRole::label($a->role) : '',
                    'order' => $roleOrder[$a->role] ?? 999,
                    'tentative' => $a->status !== '確定',
                ];
            }
            $members = collect($members)->sortBy([['order', 'asc'], ['name', 'asc']])->values()->all();

            $content = ProjectContentName::of($p, $contentNames);
            $title = trim((string) ($p->project_name ?? ''));

            return [
                'id' => $p->id,
                'dateLabel' => $p->start_date->format('n/j'),
                // ⚠ 曜日は「（土）」の形まで作って渡す（文字のすぐ後ろに Blade 命令を書くと読まれない）。
                'dowLabel' => '（'.self::WEEKDAYS[(int) $p->start_date->dayOfWeek].'）',
                'time' => trim((string) ($p->start_time ?? '')),
                'client' => trim((string) ($p->client ?? '')),
                'content' => $content,
                // 案件名がコンテンツ名と同じなら重ねて出さない。
                'title' => ($title !== '' && $title !== $content) ? $title : '',
                'place' => trim((string) ($p->location ?? '')),
                'scale' => trim((string) ($p->scale ?? '')),
                'office' => trim((string) ($p->office ?? '')),
                'cancelled' => (bool) $p->is_cancelled,
                'members' => $members,
                'dispatches' => $dispatches[$p->id] ?? [],
            ];
        })->all();
    }
}
