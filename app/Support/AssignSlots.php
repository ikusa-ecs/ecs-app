<?php

namespace App\Support;

use App\Models\ProjectSlot;
use Illuminate\Support\Collection;

/**
 * アサイン表のブロック（NO／名前／P／巡回／備考）の**行の作り方の正本**（2026-09-28 baba要望）。
 *
 * 1枚のブロックには3種類の行が混ざる：
 *   ① アサイン済みの人（assignments）
 *   ② 派遣（project_dispatches）＝会社への依頼
 *   ③ まだ人が決まっていない枠（project_slots）＝「ここはOP」「IKUSAマスト」「派遣でOK」
 *
 * 【並び順】ポジション順（D → SD → MC → OP → …）。
 *   ⚠ **Dは必ず1番上**にする。現場のアサイン表がそうなっていて、
 *     「1番のPがDかどうか」でDが決まっているかを見ているため（baba 2026-09-28）。
 *   ⚠ 同じポジションの中では、渡された順番のまま（アサイン済み → 派遣 → 枠）。
 *
 * 【NOの振り方】並べ替えたあとに 1 から順に振る。
 *   ⚠ project_slots.no は「枠どうしの順番」であって画面のNOではない。
 *     人が増えると枠は下にずれる＝それが正しい（枠＝まだ埋まっていない場所）。
 */
class AssignSlots
{
    /** 「人は未定だが、誰が出すかは決まっている」ときの印。⚠ 画面に文字を直書きしない。 */
    public const PLACEHOLDERS = ['イベプラ'];

    /** Dが決まっていないときに入れる印（baba 2026-09-28）。 */
    public const EVENT_PLANNER = 'イベプラ';

    /** 並び順（アサイン表・日別ボードと同じ）。⚠ Dが先頭であること。 */
    public const POS_PRIORITY = ['D', 'SD', 'MC', 'OP', 'FC', 'CK', 'SP', 'GUN', 'RP', 'UKE'];

    /** その印を使ってよいか。 */
    public static function isPlaceholder(?string $v): bool
    {
        return in_array((string) $v, self::PLACEHOLDERS, true);
    }

    /**
     * 案件ごとの空き枠をまとめて読む（案件の数だけ問い合わせない）。
     *
     * @param  iterable<int|string>  $projectIds
     * @return array<string, array<int, array<string, mixed>>>  project_id => 枠の配列（no順）
     */
    public static function forProjects($projectIds): array
    {
        return ProjectSlot::whereIn('project_id', collect($projectIds)->all())
            ->orderBy('no')
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $rows) => $rows->map(fn (ProjectSlot $s) => [
                'id' => $s->id,
                'no' => $s->no,
                'role' => (string) ($s->role ?? ''),
                'note' => (string) ($s->note ?? ''),
                'patrol' => $s->patrol,
                'remark' => (string) ($s->remark ?? ''),
                'placeholder' => (string) ($s->placeholder ?? ''),
            ])->values()->all())
            ->all();
    }

    /**
     * ブロックに並べる行を作る（種類を混ぜて、ポジション順に並べ、NOを振る）。
     *
     * @param  array<int, array<string, mixed>>  $members     アサイン済み（AssignSheetController が作った形）
     * @param  array<int, array<string, mixed>>  $dispatches  派遣（DispatchRows が作った形）
     * @param  array<int, array<string, mixed>>  $slots       空き枠（forProjects が作った形）
     * @return array<int, array<string, mixed>>  画面がそのまま並べる行（'kind' で種類が分かる）
     */
    public static function rows(array $members, array $dispatches, array $slots): array
    {
        $rows = [];

        foreach ($members as $m) {
            $rows[] = ['kind' => 'member', 'role' => (string) ($m['roleCode'] ?? ''), 'm' => $m];
        }
        foreach ($dispatches as $d) {
            // 派遣の役割は自由記入なので、並び順の判定にはそのまま使う（知らない言葉は後ろへ）。
            $rows[] = ['kind' => 'dispatch', 'role' => (string) ($d['role'] ?? ''), 'd' => $d];
        }
        foreach ($slots as $s) {
            $rows[] = ['kind' => 'slot', 'role' => (string) ($s['role'] ?? ''), 's' => $s];
        }

        // ポジション順。⚠ 知らない／空の役割は必ず後ろ（Dが1番上に来ることを守る）。
        // ⚠ usort は安定ではないので、元の順番を添えて比べる（同じポジションの中の並びを崩さない）。
        $withIndex = [];
        foreach ($rows as $i => $r) {
            $withIndex[] = [$r, self::priorityOf($r['role']), $i];
        }
        usort($withIndex, fn ($a, $b) => ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));

        // NOを振る。⚠ 派遣は**人数ぶんのNOを使う**（派遣10名＝「3〜12」の1行・2026-09-29 baba決定）。
        //   1行＝1名にすると、運営人数に対して空きがずれて、黄色の空き行も「あと◯名」も合わなくなる。
        //   キャンセルした派遣は薄く残すだけで、NOも人数も使わない（no＝null）。
        $out = [];
        $next = 1;
        foreach ($withIndex as [$r]) {
            if ($r['kind'] === 'dispatch') {
                if (! empty($r['d']['cancelled'])) {
                    $r['no'] = null;
                    $r['noEnd'] = null;
                } else {
                    $n = max(1, (int) ($r['d']['count'] ?? 1));
                    $r['no'] = $next;
                    $r['noEnd'] = $next + $n - 1;
                    $next += $n;
                }
            } else {
                $r['no'] = $next;
                $r['noEnd'] = $next;
                $next++;
            }
            $out[] = $r;
        }

        return $out;
    }

    /** ブロックで使い終わったNOの最後（空き行はこの次から）。 */
    public static function lastNo(array $lines): int
    {
        $last = 0;
        foreach ($lines as $l) {
            $last = max($last, (int) ($l['noEnd'] ?? 0));
        }

        return $last;
    }

    /** そのポジションの並び順。知らない・空は後ろ。 */
    public static function priorityOf(string $role): int
    {
        $i = array_search($role, self::POS_PRIORITY, true);

        return $i === false ? count(self::POS_PRIORITY) : $i;
    }

    /**
     * その案件の「Dがまだ決まっていない」か。
     *
     * ⚠ 決まっている＝**人がアサインされている**こと。枠（イベプラの印を含む）はまだ決まっていない。
     *   D決めの画面はこの判定を使って「決めるべき案件」を出す。
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    public static function directorUndecided(array $members): bool
    {
        foreach ($members as $m) {
            if ((string) ($m['roleCode'] ?? '') === 'D') {
                return false;
            }
        }

        return true;
    }

    /** その案件に「イベプラ待ち」の枠があるか（D決めの画面で目印にする）。 */
    public static function waitingForPlanner(array $slots): bool
    {
        foreach ($slots as $s) {
            if ((string) ($s['placeholder'] ?? '') === self::EVENT_PLANNER) {
                return true;
            }
        }

        return false;
    }
}
