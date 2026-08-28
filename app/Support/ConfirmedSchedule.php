<?php

namespace App\Support;

use App\Models\Assignment;
use Illuminate\Support\Carbon;

/**
 * 「その人のその日に、もう決まっている案件」の正本（2026-08-28 baba要望）。
 *
 * 【なにをするもの】
 * 出勤可能日（参加希望）を書くとき、みんな手元の表に
 * 「この日はもう〇〇の案件が入っている」と書き込む文化がある。
 * それを人が書き写さなくても ECS が自動で出す、というのがこの部品。
 *
 * 【なぜ保存しないか（大事）】
 * ⚠ 「その月の希望を出すとき、まだその案件があるかどうか分からない」（baba）。
 *   希望を出した時点でコピーして保存すると、**あとから決まった案件が一生出てこない**。
 *   なので保存しない。**画面を開くたびに assignments から数え直す**。
 *   こうすると、あとでアサインが決まっても・変わっても・消えても、次に開けば正しくなる。
 *
 * 【出す条件】
 *  ・アサインが **確定** のものだけ（仮＝声掛け中は「決まっている」とは言えない）。
 *  ・案件が **キャンセルでない**・**下書きでない**こと。
 *  ・日は `assignments.date`（その人が実際に働く日）で見る。
 *    ⚠ 案件の開催日ではない＝2日案件は2日とも出したいため。
 */
final class ConfirmedSchedule
{
    /**
     * 何人ぶんかまとめて引く（1人ずつ引かない）。
     *
     * 返す形： [ 人ID => [ "2026-9-13" => [ ['id'=>'P-…','name'=>'水合戦','role'=>'D',…], … ] ] ]
     * ⚠ 日付のキーは画面の keyOf（"Y-M-D"・ゼロ埋めなし）に合わせてある。
     *   ここを変えると出勤可能日の画面で日がずれる。
     *
     * @param  list<string>  $personIds
     * @param  Carbon|null  $from  この日から（既定＝1年前）
     * @param  Carbon|null  $to  この日まで（既定＝1年後）
     * @return array<string, array<string, list<array{id:string,name:string,role:string,roleLabel:string,client:string,time:string,office:string}>>>
     */
    public static function forPeople(array $personIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $personIds = array_values(array_unique(array_filter($personIds)));
        if ($personIds === []) {
            return [];
        }

        // ⚠ 画面の「前の月／次の月」は何年でも遡れるが、全部渡すと年々重くなる。
        //   前後1年ぶんだけ渡す（それより外はカレンダーに案件名が出ないだけで、入力はできる）。
        $today = Carbon::today();
        $from ??= $today->copy()->subYear();
        $to ??= $today->copy()->addYear();

        $rows = Assignment::query()
            ->join('projects', 'projects.id', '=', 'assignments.project_id')
            ->whereIn('assignments.staff_id', $personIds)
            // 確定だけ（仮のアサインは「もう決まっている」ではない）。
            ->where('assignments.status', '確定')
            ->whereBetween('assignments.date', [$from->toDateString(), $to->toDateString()])
            // キャンセルの案件は出さない。⚠ 昔の行は is_cancelled が null なので null も通す。
            ->where(function ($q) {
                $q->whereNull('projects.is_cancelled')->orWhere('projects.is_cancelled', false);
            })
            // 下書きの案件も出さない（まだ本決まりではないため）。
            ->where(function ($q) {
                $q->whereNull('projects.status')->orWhere('projects.status', '!=', '下書き');
            })
            ->orderBy('assignments.date')
            ->get([
                'assignments.staff_id',
                'assignments.project_id',
                'assignments.date',
                'assignments.role',
                'projects.project_name',
                'projects.content_names',
                'projects.client',
                'projects.office',
                'projects.event_start_time',
                'projects.event_end_time',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        // 略称はまとめて1回だけ引く（案件ごとに引かない）。正本＝コンテンツ台帳。
        $allNames = [];
        foreach ($rows as $row) {
            foreach (self::contentsOf($row) as $n) {
                $allNames[$n] = true;
            }
        }
        $shortMap = ContentShortName::map(array_keys($allNames));

        $out = [];
        foreach ($rows as $row) {
            $date = $row->date ? Carbon::parse($row->date) : null;
            if (! $date) {
                continue;
            }

            $role = trim((string) $row->role);

            $out[(string) $row->staff_id][$date->year.'-'.$date->month.'-'.$date->day][] = [
                'id' => (string) $row->project_id,
                // 画面のマス目は狭いので略称を優先（無ければ正式名）。
                'name' => ContentShortName::label(
                    self::contentsOf($row),
                    (string) $row->project_name,
                    true,
                    $shortMap
                ),
                'role' => $role,
                'roleLabel' => AssignmentRole::isValid($role) ? AssignmentRole::label($role) : '',
                'client' => trim((string) $row->client),
                'time' => self::span($row->event_start_time, $row->event_end_time),
                'office' => trim((string) $row->office),
            ];
        }

        return $out;
    }

    /**
     * その案件のコンテンツ名。
     * ⚠ ExperienceCount と同じ決まり＝`content_names` をそのまま使い、空なら案件名で代用する
     *   （台帳に登録していない単発コンテンツも残っているため）。
     *
     * @return list<string>
     */
    private static function contentsOf(object $row): array
    {
        $raw = $row->content_names;
        $names = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        $names = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            is_array($names) ? $names : []
        ), fn ($n) => $n !== ''));

        if ($names !== []) {
            return array_values(array_unique($names));
        }

        $fallback = trim((string) $row->project_name);

        return $fallback !== '' ? [$fallback] : [];
    }

    /** 「15:40」「17:40」→「15:40-17:40」。片方でも取れなければ空。 */
    private static function span(?string $from, ?string $to): string
    {
        $a = trim((string) $from);
        $b = trim((string) $to);

        return ($a !== '' && $b !== '') ? $a.'-'.$b : '';
    }
}
