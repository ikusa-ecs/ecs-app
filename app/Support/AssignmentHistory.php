<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * アサインの変更を、案件の編集履歴に残す係（2026-11-25 小沼さん「アサインした人も履歴に残るように」）。
 *
 * 残すもの：だれを入れた／だれを外した／役割や「仮・確定」を変えた。
 * 保存先は案件の編集履歴と同じ `project_histories`。見分けの目印＝ field が self::FIELD。
 *
 * 【しくみ】画面からの保存1回（App\Http\Middleware\RecordAssignmentHistory）のあいだだけ動く。
 *   1. 保存の途中：さわられたアサインの行について「保存の前の姿」を覚える。
 *        ・新しく出来た行 … Assignment モデルの見張り（created）から。前の姿＝なし。
 *        ・消す・書き換える行 … DBへ「消す／書き換える」命令が出る直前に、その行を読んで覚える（beforeQuery）。
 *   2. 保存が終わったら：覚えた行の「いまの姿」をDBから読み直し、前と違うものだけ履歴に書く（flush）。
 *
 * 【なぜこの形か】
 *   ・保存の入口は10か所以上あり、「いったん全員消して入れ直す」作りや、まとめて消す作り
 *     （->where(...)->delete()＝モデルの見張りを通らない）が混ざっている。入口ごとに記録を書くと必ず書き忘れる。
 *   ・1行ずつその場で書くと、変えていない人まで「外した→入れた」と並んで読めなくなる。
 *   ＝DBへの命令そのものを見張って、最後に「前と後の差」だけを書けば、どの入口でも・これから増えても残る。
 *
 * ⚠ キャンセル（status=キャンセル）は「入っていない」と同じに扱う。
 * ⚠ ここで失敗しても保存は止めない（履歴は補助の機能）。beforeQuery の中は必ず try で囲む。
 * ⚠ 見張りを通らない入れ方（DB::table('assignments')->insert など）で行を作ると「入れた」が残らない。
 *    行を作るときは Assignment::create を使う。
 */
class AssignmentHistory
{
    /** project_histories.field に入れる目印（案件の列の名前ではない）。 */
    public const FIELD = 'assignment';

    /** いま見張っているか（画面からの保存のあいだだけ true）。 */
    private static bool $active = false;

    /** 自分でDBを読んでいる最中（見張りが自分の読み出しに反応しないように）。 */
    private static bool $reading = false;

    /** 保存の前の姿。[案件ID => [人ID|日付 => 状態|null]] */
    private static array $before = [];

    /** 見張りを付けた接続（同じ接続に2回付けない）。 */
    private static ?\WeakMap $watched = null;

    /** 見張りを始める（画面の処理の始まりに呼ぶ）。 */
    public static function begin(): void
    {
        self::$before = [];
        self::$active = true;
        try {
            self::$watched ??= new \WeakMap();
            $conn = DB::connection();
            if (! isset(self::$watched[$conn])) {
                self::$watched[$conn] = true;
                $conn->beforeExecuting(function ($sql, $bindings, $connection) {
                    self::beforeQuery((string) $sql, (array) $bindings, $connection);
                });
            }
        } catch (\Throwable $e) {
            // DBに繋がらない等。履歴は補助なので、画面は止めない。
        }
    }

    /** 見張りをやめて、覚えた分を捨てる（保存が失敗したとき）。 */
    public static function discard(): void
    {
        self::$active = false;
        self::$before = [];
    }

    /** 新しい行が出来たとき（Assignment の created から）。前の姿＝なし。 */
    public static function noteCreated(Assignment $a): void
    {
        if (! self::$active || ! ProjectHistoryRecorder::enabled()) {
            return;
        }
        self::remember((string) $a->project_id, self::keyOf($a->staff_id, $a->date), null);
    }

    /**
     * DBへ命令が出る直前に呼ばれる。アサインを「消す／書き換える」命令なら、
     * その命令が当たる行を先に読んで「前の姿」として覚える。
     */
    private static function beforeQuery(string $sql, array $bindings, Connection $conn): void
    {
        if (! self::$active || self::$reading || ! ProjectHistoryRecorder::enabled()) {
            return;
        }
        // MySQL は `assignments`、SQLite は "assignments" と書く。どちらでも拾う。
        if (! preg_match('/^\s*(?:delete\s+from|update)\s+[`"]?assignments[`"]?(\s.*)?$/is', $sql, $m)) {
            return;
        }
        self::$reading = true;
        try {
            $rest = $m[1] ?? '';
            $pos = stripos($rest, ' where ');
            $where = $pos === false ? '' : substr($rest, $pos + 7);
            // 条件に使う値は、命令に渡された値の「うしろから条件の ? の数ぶん」。
            $n = substr_count($where, '?');
            $args = $n > 0 ? array_slice($bindings, -$n) : [];
            $rows = $conn->select(
                'select project_id, staff_id, date, role, role2, status from assignments'.($where !== '' ? ' where '.$where : ''),
                $args
            );
            foreach ($rows as $r) {
                self::remember((string) $r->project_id, self::keyOf($r->staff_id, $r->date), self::stateOf((array) $r));
            }
        } catch (\Throwable $e) {
            // 読めなくても保存は止めない（その分の履歴が残らないだけ）。
        } finally {
            self::$reading = false;
        }
    }

    /** 覚えた行の「いまの姿」を読み直して、前と違うものだけ履歴に書く。 */
    public static function flush(): void
    {
        $before = self::$before;
        self::discard();
        if (! $before || ! ProjectHistoryRecorder::available()) {
            return;
        }

        $pids = array_map('strval', array_keys($before));
        $projects = Project::whereIn('id', $pids)->get()->keyBy('id');

        // いまの姿。
        $after = [];
        foreach (DB::table('assignments')->whereIn('project_id', $pids)
            ->get(['project_id', 'staff_id', 'date', 'role', 'role2', 'status']) as $r) {
            $after[(string) $r->project_id][self::keyOf($r->staff_id, $r->date)] = self::stateOf((array) $r);
        }

        $staffIds = [];
        foreach ($before as $rows) {
            foreach (array_keys($rows) as $key) {
                $staffIds[] = explode('|', (string) $key, 2)[0];
            }
        }
        $names = Person::whereIn('id', array_unique($staffIds))->pluck('name', 'id');

        foreach ($before as $pid => $rows) {
            $project = $projects->get((string) $pid);
            if (! $project) {
                continue;   // 案件ごと消えた＝「案件を削除」の行が残るので、ここでは書かない
            }
            $mainDay = $project->start_date ? $project->start_date->format('Y-m-d') : '';
            foreach ($rows as $key => $was) {
                $now = $after[(string) $pid][$key] ?? null;
                if ($was === $now) {
                    continue;   // 消して入れ直しただけ＝変わっていない
                }
                [$staffId, $date] = explode('|', (string) $key, 2) + ['', ''];
                $name = (string) ($names[$staffId] ?? $staffId);
                // 開催日と違う日の行（設営・リハ・連日）は、どの日の話か分かるように日付を添える。
                $day = ($date !== '' && $date !== $mainDay) ? Carbon::parse($date)->format('n/j').' ' : '';
                $text = fn (?array $s) => $s === null ? '（なし）' : $day.$name.'（'.self::stateText($s).'）';
                $label = $was === null ? 'アサイン：入れた' : ($now === null ? 'アサイン：外した' : 'アサイン：変更');
                ProjectHistoryRecorder::recordAssignment($project, $label, $text($was), $text($now));
            }
        }
    }

    /** はじめて見た行だけ覚える（2回目からは上書きしない＝最初の姿が「保存の前」）。 */
    private static function remember(string $pid, string $key, ?array $state): void
    {
        if ($pid === '' || (isset(self::$before[$pid]) && array_key_exists($key, self::$before[$pid]))) {
            return;
        }
        self::$before[$pid][$key] = $state;
    }

    private static function keyOf($staffId, $date): string
    {
        $d = $date ? Carbon::parse($date)->format('Y-m-d') : '';

        return (string) $staffId.'|'.$d;
    }

    /** 履歴で比べる中身＝役割・2つめの役割・状態。キャンセルは「入っていない」。 */
    private static function stateOf(array $attrs): ?array
    {
        $status = (string) ($attrs['status'] ?? '仮');
        if ($status === 'キャンセル') {
            return null;
        }

        return [
            'role' => (string) ($attrs['role'] ?? ''),
            'role2' => (string) ($attrs['role2'] ?? ''),
            'status' => $status,
        ];
    }

    /** 「MC・仮」「D＋FC・確定」。役割は現場で使っている短い呼び名（コード）のまま出す。 */
    private static function stateText(array $s): string
    {
        $roles = array_filter([$s['role'], $s['role2']], fn ($r) => $r !== '');

        return ($roles ? implode('＋', $roles) : '役割なし').'・'.$s['status'];
    }
}
