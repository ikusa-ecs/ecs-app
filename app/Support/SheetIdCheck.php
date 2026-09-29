<?php

namespace App\Support;

use App\Models\Project;

/**
 * アサイン表の100行目のECS案件IDが「そのブロックの案件として本当に合っているか」（2026-09-29）。
 *
 * 【なぜ要るか】（baba「アサイン表のIDとぜんぜんばらばら」）
 *   IDの書き戻しは「前回取り込んだときの列の位置 → ID」の対応表で行う。取り込んだあとにシートで
 *   ブロックを足したり並べ替えたりすると、**列がずれたまま書き戻して、IDが隣のブロックに付く**。
 *   そのIDを信じて上書きすると、まったく別の案件を書き換えてしまう。
 *
 * 【合っているとみなす条件】日付が同じ、または コンテンツが1つでも重なる。
 *   ⚠ どちらも違えば「別の案件のID」＝使わない（取込では下見に出し、書き戻しでは消す）。
 *   ⚠ 下見で人が手で入れたIDは、これを通さない（人が決めたものが優先）。
 */
final class SheetIdCheck
{
    public static function fits(Project $p, ?string $date, string $contentRaw, ?ImportContents $contents = null): bool
    {
        if ($date !== null && $date !== '' && optional($p->start_date)->format('Y-m-d') === $date) {
            return true;
        }

        $contents ??= new ImportContents;
        $want = self::keys($contents->resolve(self::clean($contentRaw))['names']);
        $have = self::keys(is_array($p->content_names) && $p->content_names !== []
            ? $p->content_names
            : $contents->resolve((string) $p->project_name)['names']);

        return $want !== [] && array_intersect($want, $have) !== [];
    }

    /** 「(リハ)」などの印を外す（保存する案件名も外したものなので、比べるときも同じにする）。 */
    private static function clean(string $name): string
    {
        $m = ScheduleMark::detect($name);

        return $m !== null ? $m['clean'] : $name;
    }

    private static function keys(array $names): array
    {
        return array_values(array_filter(explode('|', ImportContents::namesKey($names)), fn ($s) => $s !== ''));
    }
}
