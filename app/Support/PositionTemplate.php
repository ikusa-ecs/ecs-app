<?php

namespace App\Support;

use App\Models\ContentRoleRequirement;
use App\Models\Project;

/**
 * 案件の「必要ポジションと人数」の正本（2026-09-07 に1か所へまとめた）。
 *
 * コンテンツ×規模ごとの必要人数（content_role_requirements）を、案件1件ぶんに足し合わせる。
 * 例）謎解き（中型）＝ D1・MC1・OP1・FC2 …
 *
 * ⚠ もともと AssignmentController の中に private で書いてあった。
 *   月まとめ自動アサインでも同じ計算が要るので、写さずにここへ出した。
 *   **写すと片方だけ直して食い違う**（この現場で何度も起きている）。
 *
 * ⚠ コンテンツか規模が未入力の案件は**空**を返す（＝必要ポジションが分からない）。
 *   分からないのに勝手に決めない。呼ぶ側で「人数だけ埋める」などに倒すこと。
 */
final class PositionTemplate
{
    /**
     * 案件の必要ポジション。[役割コード => 人数]。並びは AssignmentRole の定義順。
     *
     * @return array<string, int>
     */
    public static function of(Project $project): array
    {
        return self::forContents(
            is_array($project->content_ids) ? array_filter($project->content_ids) : [],
            $project->scale
        );
    }

    /**
     * 案件になる前（CSV取込の途中など）でも使える形。
     *
     * ⚠ 中身は of() と同じ＝**計算はここ1か所**。案件の形になっていない段階でも
     *   同じ答えになるように分けただけ（写して2つにしない）。
     *
     * @param  array<int, string>  $contentIds
     * @return array<string, int>
     */
    public static function forContents(array $contentIds, ?string $scale): array
    {
        $contentIds = array_values(array_filter($contentIds));
        if (empty($contentIds) || ! $scale) {
            return [];
        }

        $rows = ContentRoleRequirement::whereIn('content_id', $contentIds)
            ->where('scale', $scale)
            ->where('count', '>', 0)
            ->get(['position', 'count']);

        $sum = [];
        foreach ($rows as $r) {
            if (! AssignmentRole::isValid($r->position)) {
                continue;   // 表記ゆれ・未知コードは無視（正本 AssignmentRole に寄せる）
            }
            $sum[$r->position] = ($sum[$r->position] ?? 0) + (int) $r->count;
        }

        // 表示順は AssignmentRole の定義順（D→SD→OP→…）にそろえる。
        $ordered = [];
        foreach (array_keys(AssignmentRole::LABELS) as $code) {
            if (! empty($sum[$code])) {
                $ordered[$code] = $sum[$code];
            }
        }

        return $ordered;
    }
}
