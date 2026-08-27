<?php

namespace App\Support;

use App\Models\Assignment;
use Illuminate\Support\Carbon;

/**
 * 経験回数の正本（2026-08-27 baba要望「自動で数えて見られるようにしたい」）。
 *
 * 【数え方（baba決定）】
 *  ・数えるのは「**確定**のアサイン」で「**開催日が過ぎた**もの」だけ。
 *    ＝実際に出たものだけ数える。仮のアサイン・これからの案件は数えない。
 *  ・**キャンセルの案件は数えない**（実施していないため）。
 *  ・出すのは3つ：**コンテンツごと** / **ポジションごと** / **コンテンツ×ポジション**。
 *
 * 【なぜ表に保存しないか（大事）】
 * ⚠ 設計書8章の `staff_content_experience` / `staff_role_experience` は
 *   「数を保存しておく」作りだが、**そこには書き込まない**。
 *   アサインを直したり案件をキャンセルしたりしたときに**数だけ古いまま残る**（写しは必ず腐る）。
 *   ここでは毎回 assignments から数える。案件は年に数百件なので速さは問題にならない。
 *
 * 【数え方の細かい決め】
 *  ・「回数」は**案件の数**で数える。同じ案件で複数日あっても1回
 *    （`assignments` は「案件×人×日」で1行なので、行数を数えると2日案件が2回になる）。
 *    出勤した日数は別に `days` で返す。
 *  ・**1つの案件に複数のコンテンツ**があれば、**それぞれに1回**足す
 *    （両方やったので両方の経験）。そのため「コンテンツごとの合計」は `projects` と一致しない。
 *  ・ポジションが空の行は「ポジションごと」には数えない（何をやったか分からないため）。
 *    ⚠ ただし `projects`（通算）には数える＝現場に出たことは事実なので。
 *  ・⚠ イベント数の集計（`EventCount`）とは**別**。体験会やEXPOも「経験」としては数える。
 */
final class ExperienceCount
{
    /** 空の集計（その人のアサインが1件も無いとき）。 */
    private const EMPTY = [
        'projects' => 0,
        'days' => 0,
        'byContent' => [],
        'byRole' => [],
        'byContentRole' => [],
    ];

    /**
     * 1人ぶんの経験回数。
     *
     * @return array{projects:int, days:int,
     *               byContent: list<array{name:string, count:int, last:?string}>,
     *               byRole: list<array{role:string, label:string, count:int, last:?string}>,
     *               byContentRole: array<string, array<string,int>>}
     */
    public static function forStaff(string $staffId): array
    {
        return self::forMany([$staffId])[$staffId] ?? self::EMPTY;
    }

    /**
     * 何人ぶんかまとめて数える（名簿の一覧で1人ずつ引かないため）。
     *
     * ⚠ 一覧で `forStaff()` をループで呼ぶと人数ぶんSQLが走る。必ずこちらを使う。
     *
     * @param  list<string>  $staffIds
     * @return array<string, array{projects:int, days:int, byContent:list<array>, byRole:list<array>, byContentRole:array}>
     */
    public static function forMany(array $staffIds): array
    {
        $staffIds = array_values(array_unique(array_filter($staffIds)));
        if ($staffIds === []) {
            return [];
        }

        $today = Carbon::today();

        $rows = Assignment::query()
            ->join('projects', 'projects.id', '=', 'assignments.project_id')
            ->whereIn('assignments.staff_id', $staffIds)
            // 確定だけ（仮＝声掛け中は数えない）。
            ->where('assignments.status', '確定')
            // 開催日が過ぎたものだけ。⚠ 日付は 00:00:00 付きで入るので whereDate で比べる
            //   （文字列の完全一致だと取りこぼす。取込で実際に踏んだ罠）。
            ->whereDate('projects.start_date', '<', $today->toDateString())
            // キャンセルの案件は数えない。⚠ 昔の行は is_cancelled が null なので null も通す。
            ->where(function ($q) {
                $q->whereNull('projects.is_cancelled')->orWhere('projects.is_cancelled', false);
            })
            ->get([
                'assignments.staff_id',
                'assignments.project_id',
                'assignments.role',
                'projects.start_date',
                'projects.content_names',
                'projects.project_name',
            ]);

        // 集計は PHP 側で行う（コンテンツが JSON の配列なので SQL では数えられない）。
        $acc = [];
        foreach ($rows as $row) {
            $id = (string) $row->staff_id;
            $acc[$id] ??= [
                'projectIds' => [],       // 案件ID => true（回数＝案件の数）
                'days' => 0,
                'content' => [],          // コンテンツ名 => ['count'=>, 'last'=>, 'projects'=>[]]
                'role' => [],             // 役割コード => ['count'=>, 'last'=>, 'projects'=>[]]
                'contentRole' => [],      // コンテンツ名 => 役割 => 案件ID => true
            ];
            $a = &$acc[$id];

            $projectId = (string) $row->project_id;
            $date = $row->start_date ? Carbon::parse($row->start_date)->toDateString() : null;

            $a['projectIds'][$projectId] = true;
            $a['days']++;

            $role = trim((string) $row->role);
            $hasRole = AssignmentRole::isValid($role);

            if ($hasRole) {
                $a['role'][$role]['projects'][$projectId] = true;
                $a['role'][$role]['last'] = self::later($a['role'][$role]['last'] ?? null, $date);
            }

            foreach (self::contentsOf($row) as $name) {
                $a['content'][$name]['projects'][$projectId] = true;
                $a['content'][$name]['last'] = self::later($a['content'][$name]['last'] ?? null, $date);
                if ($hasRole) {
                    $a['contentRole'][$name][$role][$projectId] = true;
                }
            }

            unset($a);
        }

        // 画面に渡す形に整える。
        $out = [];
        foreach ($staffIds as $id) {
            if (! isset($acc[$id])) {
                $out[$id] = self::EMPTY;

                continue;
            }
            $a = $acc[$id];

            $byContent = [];
            foreach ($a['content'] as $name => $info) {
                $byContent[] = [
                    'name' => $name,
                    'count' => count($info['projects']),
                    'last' => $info['last'] ?? null,
                ];
            }
            // 多い順 → 同じ回数なら名前順（毎回同じ並びになるように）。
            usort($byContent, fn ($x, $y) => [$y['count'], $x['name']] <=> [$x['count'], $y['name']]);

            // ポジションは決まった並び（D→OP→MC→…）で出す＝画面ごとに並びが変わらないように。
            $byRole = [];
            foreach (array_keys(AssignmentRole::LABELS) as $code) {
                if (! isset($a['role'][$code])) {
                    continue;
                }
                $byRole[] = [
                    'role' => $code,
                    'label' => AssignmentRole::label($code),
                    'count' => count($a['role'][$code]['projects']),
                    'last' => $a['role'][$code]['last'] ?? null,
                ];
            }

            $byContentRole = [];
            foreach ($a['contentRole'] as $name => $roles) {
                foreach ($roles as $code => $projects) {
                    $byContentRole[$name][$code] = count($projects);
                }
            }

            $out[$id] = [
                'projects' => count($a['projectIds']),
                'days' => $a['days'],
                'byContent' => $byContent,
                'byRole' => $byRole,
                'byContentRole' => $byContentRole,
            ];
        }

        return $out;
    }

    /**
     * その案件のコンテンツ名。
     * ⚠ `content_names`（画面で入れた名前そのもの）を使う。台帳に登録していない
     *   単発コンテンツもここに残っているため。空なら案件名で代用する。
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

    /** 2つの日付のうち新しいほう（null は無視）。 */
    private static function later(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }
}
