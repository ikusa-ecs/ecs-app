<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 人ごとの「その月のアサイン明細」を作る（正本・2026-10-13）。
 * 使う画面＝請求書チェック・手当管理表（スタッフ）／社員別アサイン一覧（社員）。
 * ⚠ 画面ごとに明細を作り直さない（項目を足したら両方に出るように）。
 */
final class PersonMonthCases
{
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /**
     * その月のアサイン（キャンセル以外）を人ごとに。
     *
     * @param  list<string>  $personIds
     * @return array{byPerson: array<string, list<array<string, mixed>>>, projects: Collection}
     */
    public static function build(array $personIds, Carbon $month, array $extraProjectIds = []): array
    {
        $rows = Assignment::whereIn('staff_id', $personIds)
            ->whereDate('date', '>=', $month->format('Y-m-d'))
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->format('Y-m-d'))
            ->where('status', '!=', 'キャンセル')
            ->orderBy('date')->get(['project_id', 'staff_id', 'role', 'status', 'date']);

        $projects = Project::whereIn('id', $rows->pluck('project_id')->merge($extraProjectIds)->filter()->unique())->get()->keyBy('id');
        $contentNames = Content::pluck('content_name', 'id');

        $byPerson = [];
        foreach ($rows as $a) {
            $pr = $projects->get($a->project_id);
            $d = Carbon::parse($a->date);
            $byPerson[$a->staff_id][] = [
                'ymd' => $d->format('Y-m-d'),
                'roleCode' => (string) $a->role,
                'lodging' => $pr ? (string) ($pr->lodging ?? '') : '',
                'date' => $d->format('n/j').'('.self::WEEKDAYS[$d->dayOfWeek].')',
                'projectId' => $a->project_id,
                'name' => $pr ? ProjectContentName::of($pr, $contentNames, (string) $pr->project_name) : $a->project_id,
                'client' => $pr ? (string) ($pr->client ?? '') : '',
                'dayType' => $pr ? (string) ($pr->date_type ?? '本番') : '',
                'role' => AssignmentRole::label((string) $a->role),
                // 実施形態・集合／解散・会場住所（2026-10-13 baba「住所と集合解散・リアル/リアルロング/オンラインも」）。
                'format' => $pr ? trim((string) ($pr->format ?? '')) : '',
                'meet' => $pr ? trim((string) ($pr->start_time ?? '')) : '',
                'leave' => $pr ? trim((string) ($pr->end_time ?? '')) : '',
                'place' => $pr ? trim((string) preg_replace('/\s+/u', ' ', (string) ($pr->location ?? ''))) : '',
                'status' => (string) $a->status,
                // 中止になった案件も消さずに印を付けて出す（キャンセル料の請求が来ることがあるため）。
                'cancelled' => $pr ? (bool) $pr->is_cancelled : false,
            ];
        }

        return ['byPerson' => $byPerson, 'projects' => $projects, 'contentNames' => $contentNames];
    }
}
