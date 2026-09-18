<?php

namespace App\Support;

/**
 * 宿泊（無／前泊有／一部前泊有／後泊あり／前後泊あり）の読み取りの正本。2026-09-18。
 *
 * 【なぜ要るか】
 * ⚠ 「前泊があるか」の判定が **3か所にコピーされていて、どれも `前泊` という文字を探すだけ**だった。
 *   そのため「**前後泊あり**」（前泊と後泊の両方）が **前泊なし扱い**になっていた。
 *   ＝日別ボードに「前泊」の札が出ず、LINEの概要文に前泊集合の行も出ず、
 *     案件登録では前泊の集合時間の欄すら開かなかった。
 *   選べる言葉を増やすたびに同じ穴が空くので、判定をここ1か所にまとめる。
 *
 * ⚠ 案件登録で選べる言葉（projects.lodging）＝空（未定）／無／前泊有／一部前泊有／後泊あり／前後泊あり
 *   言葉を増やすときは、下の PRE_STAY_WORDS も見直すこと。
 */
final class Lodging
{
    /** 「前泊がある」と数える言葉（正本）。⚠ 「前後泊」を忘れない。 */
    public const PRE_STAY_WORDS = ['前泊', '前後泊'];

    /** 前泊があるか。 */
    public static function hasPreStay(?string $lodging): bool
    {
        $v = trim((string) $lodging);
        if ($v === '') {
            return false;
        }

        foreach (self::PRE_STAY_WORDS as $word) {
            if (str_contains($v, $word)) {
                return true;
            }
        }

        return false;
    }

    /** 画面に出す宿泊の文字。空なら「無」。 */
    public static function label(?string $lodging): string
    {
        $v = trim((string) $lodging);

        return $v !== '' ? $v : '無';
    }
}
