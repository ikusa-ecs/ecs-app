<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * 案件の見出しに出す「コンテンツ名」の正本。2026-09-18。
 *
 * 【なぜ要るか】
 * ⚠ それまで各画面が `content_ids[0]`（＝**1個目のコンテンツだけ**）を見出しにしていた。
 *   案件登録で複数のコンテンツを選んでも、一覧には最初の1つしか出ていなかった
 *   （2026-09-18 baba指摘）。同じ1行が **10か所にコピーされていた**ので、
 *   1か所直しても他の画面では1個目のままになる。ここを見るようにして、直す場所を1つにする。
 *
 * 【名前をどこから取るか（この順番）】
 *  ① projects.content_names … 案件登録で入力された名前そのもの。
 *     台帳に登録しない「単発コンテンツ」もここに入っているので、いちばん確か。
 *  ② projects.content_ids → コンテンツ台帳の名前。①が無い古い案件用。
 *  ③ どちらも分からなければ projects.project_name（＝保存時に「A・B」と作ってある）。
 *
 * ⚠ つなぎ方は「・」（中黒）。案件の正式名（project_name）と同じ書き方に揃えてある。
 *   変えるならここ1か所（画面ごとに書き分けない）。
 */
final class ProjectContentName
{
    /** 複数のコンテンツをつなぐ文字。⚠ 案件の正式名（project_name）と揃えてある。 */
    public const SEPARATOR = '・';

    /**
     * コンテンツ名の一覧（配列）。
     *
     * @param  array<int|string, string>|Collection  $master  コンテンツID → 名前。
     *                                                        渡すと台帳を引き直さない（一覧で毎回引かないため）。
     * @return array<int, string>
     */
    public static function names(Project $project, $master = []): array
    {
        // ① 入力された名前そのもの（単発コンテンツもここに入る）
        if (is_array($project->content_names) && $project->content_names) {
            $names = array_values(array_filter(array_map(
                fn ($n) => trim((string) $n),
                $project->content_names
            )));

            if ($names) {
                return $names;
            }
        }

        $ids = is_array($project->content_ids) ? array_filter($project->content_ids) : [];

        if (! $ids) {
            return [];
        }

        // ② 台帳の名前。呼び出し側が対応表をくれていればそれを使う。
        $map = $master instanceof Collection ? $master : collect($master);

        $names = [];
        foreach ($ids as $cid) {
            if (isset($map[$cid])) {
                $names[] = (string) $map[$cid];
            }
        }

        // 対応表を渡してもらえなかったときだけ台帳を引く（1件だけ作るときの保険）。
        if (! $names && $map->isEmpty()) {
            $found = Content::whereIn('id', $ids)->pluck('content_name', 'id');
            foreach ($ids as $cid) {
                if (isset($found[$cid])) {
                    $names[] = (string) $found[$cid];
                }
            }
        }

        return $names;
    }

    /**
     * 見出しに出す文字列。複数あれば「A・B」とつなぐ。
     *
     * @param  array<int|string, string>|Collection  $master    コンテンツID → 名前
     * @param  string|null  $fallback  分からないときに出す文字。既定＝案件の正式名。
     *                                 空文字を渡すと「分からなければ空」になる（リマインドの行スキップ用）。
     */
    public static function of(Project $project, $master = [], ?string $fallback = null): string
    {
        $names = self::names($project, $master);

        if ($names) {
            return implode(self::SEPARATOR, $names);
        }

        return $fallback ?? (string) $project->project_name;
    }
}
