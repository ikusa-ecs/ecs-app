<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-25 のアサインを、編集履歴の「アサイン」ページに後から起こす（小沼さん 11/25
 * 「アサイン編集も今日だけでいいからだしてくれる？」）。
 *
 * 【なぜ要るか】アサインの履歴は、この更新を本番に入れた後のぶんからしか残らない。
 *   その日の朝から入れたぶんも見たい、という要望。アサインの行には「だれが・いつ入れたか」
 *   「だれが・いつ確定にしたか」が残っているので（App\Support\AssignmentStamp）、そこから起こす。
 *
 * 【起こせるもの】入れた／確定にした。**外した人は起こせない**（行が消えていて、手がかりが無い）。
 * ⚠ 案件別アサインは保存のたびに行を作り直すので、変えていない人も「入れた」として出ることがある。
 *    後から起こした行には、出どころに SOURCE の文字を入れて見分けられるようにする。
 * ⚠ 2回流しても増えないように、SOURCE の行がもうあれば何もしない。
 */
return new class extends Migration
{
    private const SOURCE = '後から起こした記録（11/25のぶん）';
    private const FROM = '2026-11-25 00:00:00';

    public function up(): void
    {
        if (! Schema::hasTable('project_histories') || ! Schema::hasTable('assignments')
            || ! Schema::hasColumn('project_histories', 'source')
            || ! Schema::hasColumn('assignments', 'confirmed_at')) {
            return;
        }
        if (DB::table('project_histories')->where('source', self::SOURCE)->exists()) {
            return;
        }

        $rows = DB::table('assignments')
            ->where('status', '!=', 'キャンセル')
            ->where(fn ($q) => $q->where('assigned_at', '>=', self::FROM)->orWhere('confirmed_at', '>=', self::FROM))
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        $projects = DB::table('projects')->whereIn('id', $rows->pluck('project_id')->unique())
            ->get(['id', 'project_name', 'start_date'])->keyBy('id');
        $peopleIds = $rows->pluck('staff_id')->merge($rows->pluck('assigned_by'))->merge($rows->pluck('confirmed_by'))
            ->filter()->unique();
        $names = DB::table('people')->whereIn('id', $peopleIds)->pluck('name', 'id');
        $hasRole2 = Schema::hasColumn('assignments', 'role2');

        $inserts = [];
        foreach ($rows as $a) {
            $p = $projects->get($a->project_id);
            if (! $p) {
                continue;
            }
            $roles = array_filter([(string) $a->role, $hasRole2 ? (string) ($a->role2 ?? '') : ''], fn ($r) => $r !== '');
            $role = $roles ? implode('＋', $roles) : '役割なし';
            $name = (string) ($names[$a->staff_id] ?? $a->staff_id);
            // 開催日と違う日の行（設営・リハ・連日）は日付を添える（AssignmentHistory と同じ出し方）。
            $date = $a->date ? Carbon::parse($a->date)->format('Y-m-d') : '';
            $main = $p->start_date ? Carbon::parse($p->start_date)->format('Y-m-d') : '';
            $day = ($date !== '' && $date !== $main) ? Carbon::parse($date)->format('n/j').' ' : '';
            $who = fn (string $status) => $day.$name.'（'.$role.'・'.$status.'）';

            $assignedToday = $a->assigned_at && (string) $a->assigned_at >= self::FROM;
            $confirmedToday = $a->status === '確定' && $a->confirmed_at && (string) $a->confirmed_at >= self::FROM;
            // 入れたのと確定が同時（1分以内）なら「確定で入れた」の1行にまとめる。
            $together = $assignedToday && $confirmedToday
                && abs(Carbon::parse($a->confirmed_at)->diffInSeconds(Carbon::parse($a->assigned_at))) <= 60;

            $base = [
                'project_id' => (string) $a->project_id,
                'project_name' => $p->project_name,
                'action' => 'updated',
                'field' => 'assignment',
                'source' => self::SOURCE,
            ];
            if ($assignedToday) {
                $inserts[] = $base + [
                    'field_label' => 'アサイン：入れた',
                    'old_value' => '（なし）',
                    'new_value' => $who($together ? '確定' : '仮'),
                    'person_id' => $a->assigned_by,
                    'person_name' => $a->assigned_by ? ($names[$a->assigned_by] ?? null) : null,
                    'created_at' => $a->assigned_at,
                    'updated_at' => $a->assigned_at,
                ];
            }
            if ($confirmedToday && ! $together) {
                $inserts[] = $base + [
                    'field_label' => 'アサイン：変更',
                    'old_value' => $who('仮'),
                    'new_value' => $who('確定'),
                    'person_id' => $a->confirmed_by,
                    'person_name' => $a->confirmed_by ? ($names[$a->confirmed_by] ?? null) : null,
                    'created_at' => $a->confirmed_at,
                    'updated_at' => $a->confirmed_at,
                ];
            }
        }

        // 履歴の画面は id の新しい順に並べる＝古い時刻のものから順に入れる。
        usort($inserts, fn ($x, $y) => strcmp((string) $x['created_at'], (string) $y['created_at']));
        foreach (array_chunk($inserts, 200) as $chunk) {
            DB::table('project_histories')->insert($chunk);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_histories') && Schema::hasColumn('project_histories', 'source')) {
            DB::table('project_histories')->where('source', self::SOURCE)->delete();
        }
    }
};
