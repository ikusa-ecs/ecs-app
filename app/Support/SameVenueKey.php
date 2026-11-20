<?php

namespace App\Support;

/**
 * 「同じ日・同じ会場・同じお客様」の案件を1件に数えるための合言葉（2026-11-20 小沼さん決定）。
 *
 * なぜ要るか：
 *   同じ会場で同じお客様のコンテンツを2つやる日（例：ビッグサイトで「チャンバラ」と「防災ヒーロー」）は、
 *   ECSでは案件が2つに分かれている。同じ人が両方のDをかけもちすると、件数が2件になっていた。
 *   アサイン表（スプシ）では1件に数えているので、ECSもそろえる。
 *
 * 決まり：
 *   ・**日付・お客様名・会場の3つがそろって同じ**ときだけ、同じ合言葉を返す。
 *   ・お客様名か会場が空のときは null（＝まとめない）。空どうしを「同じ」と見ると、関係ない案件までまとまる。
 *   ・人が「これは数える・数えない」を選ぶ印は作らない（付け忘れで数字がずれるため）。
 *   ・空白（全角も）と末尾の「様」は無視して比べる。
 *
 * 使い方：人ごとに、この合言葉が同じものは1回だけ数える（合言葉が null のものはそのまま1件ずつ）。
 * ⚠ 件数を出す画面を足したら、EventCount::isMainDay（本番日だけ）とあわせてここも通す。
 * ⚠ 手当・請求書・繁忙期ボーナス・経験回数には使わない（お金と経験は案件ごと）。
 */
class SameVenueKey
{
    public static function of(?string $date, ?string $client, ?string $location): ?string
    {
        $d = substr(trim((string) $date), 0, 10);
        $c = self::norm((string) $client);
        $l = self::norm((string) $location);
        if ($d === '' || $c === '' || $l === '') {
            return null;
        }

        return $d.'|'.$c.'|'.$l;
    }

    /**
     * 行の並びから、同じ合言葉の2件目からを外す（合言葉が null の行は全部残す）。
     *
     * @param  array<int, mixed>  $items
     * @param  callable(mixed): ?string  $keyOf
     * @return array<int, mixed>
     */
    public static function unique(array $items, callable $keyOf): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $k = $keyOf($it);
            if ($k !== null) {
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
            }
            $out[] = $it;
        }

        return $out;
    }

    private static function norm(string $s): string
    {
        $s = (string) preg_replace('/[\s\x{3000}]+/u', '', $s);
        $s = (string) preg_replace('/様$/u', '', $s);

        return mb_strtolower($s);
    }
}
