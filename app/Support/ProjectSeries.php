<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * 連日イベント（同じ案件を何日かに分けて開催するもの）のまとまり＝「シリーズ」の正本。
 * 2026-09-15 baba要望（FBシート No.16 桑江さん「宿泊をはさむ連日イベントの連携」）。
 *
 * 【babaの言葉】「連携できるようにしてほしい。できれば案件作成の時に同じ案件は
 *   複数の日程を選択できる。連携してスタッフの画面には同じ案件で複数出れる方を
 *   優先します。みたいな感じにできたらよいかも。」
 *
 * 【どう持っているか】**新しい列は作っていない。**
 *   もともと「予備日・リハ日・前日設営」を本番に紐づける `parent_project_id` があり、
 *   ピックアップ画面はすでに「同じ親を持つ**本番**が2件以上なら◯日目／全◯日」と
 *   出していた。連日イベントはそれと同じ形なので、その決まりをここに1か所へまとめた。
 *
 *   まとまりの見分け方＝`parent_project_id ?: id`（これを「根っこ」と呼ぶ）。
 *   シリーズに入るのは **date_type が「本番」の案件だけ**。
 *   ⚠ 前日設営・リハ日・予備日は同じ根っこを持つが、シリーズの「◯日目」には数えない
 *     （本番が3日あるイベントで「全4日」と出ると、お客様に出す日数と食い違う）。
 *
 * ⚠ 数え方をここ以外に書かない。ピックアップ・スタッフ画面・アサインの3か所で
 *   別々に数えると、同じ案件が「2日目」と「3日目」に見える。
 */
final class ProjectSeries
{
    /** その案件が属するまとまりの「根っこ」のID。 */
    public static function rootId(Project $p): string
    {
        return $p->parent_project_id ?: $p->id;
    }

    /**
     * 案件の一覧から「根っこID => その日程（本番のみ・開催日順）」を作る。
     *
     * @param  iterable<Project>  $projects
     * @return array<string, list<Project>>
     */
    public static function group(iterable $projects): array
    {
        $out = [];
        foreach ($projects as $p) {
            if (($p->date_type ?? '本番') !== '本番') {
                continue;   // 前日設営・リハ日・予備日は数えない
            }
            $out[self::rootId($p)][] = $p;
        }
        foreach ($out as $root => $rows) {
            usort($rows, fn ($a, $b) => strcmp(
                (string) optional($a->start_date)->format('Y-m-d'),
                (string) optional($b->start_date)->format('Y-m-d')
            ));
            $out[$root] = $rows;
        }

        return $out;
    }

    /**
     * 「この案件は何日目／全何日か」。1日だけの案件は null（バッジを出さない）。
     *
     * @param  array<string, list<Project>>  $groups  {@see group()} の結果
     * @return array{idx:int, total:int, dates:list<string>}|null
     */
    public static function positionOf(Project $p, array $groups): ?array
    {
        if (($p->date_type ?? '本番') !== '本番') {
            return null;
        }
        $rows = $groups[self::rootId($p)] ?? [];
        if (count($rows) <= 1) {
            return null;   // 単発（連日イベントではない）
        }

        $idx = 0;
        $dates = [];
        foreach ($rows as $i => $row) {
            if ($row->id === $p->id) {
                $idx = $i + 1;
            }
            $dates[] = (string) optional($row->start_date)->format('n/j');
        }

        return ['idx' => $idx, 'total' => count($rows), 'dates' => $dates];
    }

    /**
     * この案件と同じ連日イベントの「ほかの日」の案件ID。
     * ＝「複数日に出られる人を優先する」ための材料。
     *
     * @return list<string>
     */
    public static function siblingIds(Project $p): array
    {
        $root = self::rootId($p);

        return Project::query()
            ->where('date_type', '本番')
            ->where(fn ($q) => $q->where('id', $root)->orWhere('parent_project_id', $root))
            ->where('id', '!=', $p->id)
            ->pluck('id')
            ->all();
    }

    /**
     * 連日イベントかどうか（ほかの日が1件でもあるか）。
     */
    public static function isSeries(Project $p): bool
    {
        return ($p->date_type ?? '本番') === '本番' && self::siblingIds($p) !== [];
    }

    /**
     * まとめて引くための補助：案件の集まりから、関係する根っこの本番案件を全部取り直す。
     * ⚠ 画面に出ている案件だけで数えると、絞り込みで隠れた日が抜けて「全2日」になる。
     *
     * @param  Collection<int, Project>|iterable<Project>  $projects
     * @return array<string, list<Project>>
     */
    public static function groupFor(iterable $projects): array
    {
        $roots = [];
        foreach ($projects as $p) {
            $roots[self::rootId($p)] = true;
        }
        if (! $roots) {
            return [];
        }
        $keys = array_keys($roots);

        $all = Project::query()
            ->where('date_type', '本番')
            ->where(fn ($q) => $q->whereIn('id', $keys)->orWhereIn('parent_project_id', $keys))
            ->get();

        return self::group($all);
    }
}
