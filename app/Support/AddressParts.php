<?php

namespace App\Support;

/**
 * 住所（自由記述の1本）から「都道府県」と「市区町村」を切り出す正本（2026-09-03）。
 *
 * ⚠ 会場は `projects.location` に住所が丸ごと入っているだけなので、
 *   場所で探すには切り出しが必要。切り出した結果は projects.prefecture / city に写す。
 *
 * 【決めていること】
 * ⚠ **推測しない。** 都道府県が書かれていなければ空を返す（「渋谷区…」を勝手に「東京都」にしない）。
 *   間違って埋めると、あとで人が見ても気づけない。
 * ⚠ 政令市の「区」は市までで止める（例「千葉県千葉市美浜区中瀬」→ 市区町村＝「千葉市」）。
 *   隣の市かどうかを見たいだけなので、区まで分けると細かすぎて当たらなくなる。
 * ⚠ 「（未定）」「オンライン」などは住所ではないので、両方とも空になる。
 */
final class AddressParts
{
    /**
     * 住所 → ['prefecture' => '千葉県', 'city' => '流山市']。
     * 取れなかったところは '' を返す。
     *
     * @return array{prefecture: string, city: string}
     */
    public static function of(mixed $location): array
    {
        $s = trim((string) $location);
        if ($s === '') {
            return ['prefecture' => '', 'city' => ''];
        }

        // 全角の空白・記号でつまずかないよう、先頭の飾りだけ落とす。
        $s = ltrim($s, "　 \t\r\n＠@");

        $pref = '';
        foreach (Prefectures::ALL as $name) {
            if (mb_strpos($s, $name) === 0) {
                $pref = $name;
                $s = mb_substr($s, mb_strlen($name));
                break;
            }
        }

        // 市区町村。⚠ 「◯◯市◯◯区」は市までで止める（区まで分けると細かすぎる）。
        $city = '';
        if (preg_match('/^(.{1,8}?[市町村])/u', $s, $m)) {
            $city = $m[1];
        } elseif (preg_match('/^(.{1,8}?[区郡])/u', $s, $m)) {
            // 東京23区のように、市が無くて区から始まるもの。
            $city = $m[1];
        }

        return ['prefecture' => $pref, 'city' => $city];
    }

    /** 画面に短く出す用（「千葉県流山市」）。両方とも取れなければ '' 。 */
    public static function shortOf(mixed $location): string
    {
        $p = self::of($location);

        return $p['prefecture'].$p['city'];
    }
}
