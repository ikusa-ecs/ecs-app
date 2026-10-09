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
    /** 区切り記号（ImportContents と同じ）。 */
    private const SEPARATORS = '/[・･＋+／\/、,，＆&×\r\n]+/u';

    public static function fits(Project $p, ?string $date, string $contentRaw, ?ImportContents $contents = null): bool
    {
        if ($date !== null && $date !== '' && optional($p->start_date)->format('Y-m-d') === $date) {
            return true;
        }
        // 日付が違うときは、日程種別（本番／リハ日／前日設営／予備日）も合っていること（2026-10-09 baba報告）。
        // ⚠ 10/25「鷹狩(リハ)」のブロックが、コンテンツが重なるだけで 10/26 の本番の案件に
        //   「シートのIDでつながっています」になっていた。リハと本番は別の案件。
        $blockKind = ScheduleMark::detect($contentRaw)['kind'] ?? '本番';
        if ($blockKind !== (($p->date_type ?? '') ?: '本番')) {
            return false;
        }

        return self::overlap($p, $contentRaw, $contents ?? new ImportContents);
    }

    /**
     * 日付**と**コンテンツの両方が合うか（「前回の列 → ID」の古い記録を使うときの、きびしいほうの確かめ）。
     * ⚠ 記録は列の位置で覚えているだけなので、同じ日のブロックを入れ替えると日付だけでは見分けられない。
     */
    public static function fitsStrict(Project $p, ?string $date, string $contentRaw, ?ImportContents $contents = null): bool
    {
        if ($date === null || $date === '' || optional($p->start_date)->format('Y-m-d') !== $date) {
            return false;
        }

        return self::overlap($p, $contentRaw, $contents ?? new ImportContents);
    }

    /** コンテンツが1つでも重なるか。台帳に無い名前でも、区切り記号で分けた1つずつも比べる。 */
    private static function overlap(Project $p, string $contentRaw, ImportContents $contents): bool
    {
        $want = self::namesOf(self::clean($contentRaw), $contents);
        $have = [];
        foreach (is_array($p->content_names) && $p->content_names !== [] ? $p->content_names : [(string) $p->project_name] as $n) {
            $have = array_merge($have, self::namesOf((string) $n, $contents));
        }

        return $want !== [] && array_intersect($want, $have) !== [];
    }

    /** 名前 → 比べる形の一覧（台帳でつないだ名前＋区切り記号で分けたそのままの名前）。 */
    private static function namesOf(string $raw, ImportContents $contents): array
    {
        $parts = preg_split(self::SEPARATORS, $raw) ?: [];

        return array_values(array_unique(array_merge(
            self::keys($contents->resolve($raw)['names']),
            self::keys($parts),
            self::keys([$raw])
        )));
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
