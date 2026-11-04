<?php

namespace App\Support;

use App\Models\Person;

/**
 * アサイン表の「希望」の列（K28から下）に書く数を作る（2026-11-04 小沼さん要望）。
 *
 * 【なぜ要るか】
 * アサイン表の左の名簿（I列＝東京バイト名／J列＝数／K列＝希望／L列＝割合）の K 列は、
 * もともと別ファイル「アサイン2604～」から希望を引く数式だった。
 * 希望をECSで集めるようになってそのファイルが9月で止まり、10月以降は空になっていた。
 * そこで毎朝のGASがシートを送ってきたときに、ECSの希望数を返して K 列に書いてもらう。
 *
 * ⚠ 数え方は App\Support\WishCount（希望まとめ・月まとめ自動アサインと同じ）。ここで数え直さない。
 * ⚠ 書くのは**スタッフだけ**。社員・ECSに見つからない名前・同じ名前が2人いる名前は空欄にする。
 * ⚠ 行・列を変えるときはここだけ直す（GASはECSが返した行・列に書くだけ）。
 */
final class SheetWishColumn
{
    /** 名簿の1行目（1はじまり）。27行目は見出し。 */
    public const FIRST_ROW = 28;

    /** 名簿の最後の行。J列の数式（MAP(I28:I150…)）と同じ範囲。 */
    public const LAST_ROW = 150;

    /** 名前の列（0はじまり）＝I列。 */
    public const NAME_COL = 8;

    /** 書き込む列（0はじまり）＝K列。 */
    public const WISH_COL = 10;

    /** 割合の列（0はじまり）＝L列。K から計算される数式なので、K と一緒に「変わった」に数えない。 */
    public const RATIO_COL = 11;

    /** 27行目のK列の見出し。 */
    public const HEADER = '希望';

    /**
     * シートの中身から、K28から下に書く値を作る。
     *
     * @param  array<int, array<int, string>>  $rows  シートの中身（0はじまり）
     * @param  string  $period  'YYYY-MM'
     * @return array<int, string>  K28 からの値（名簿の最後の名前まで）。名前が1つも無ければ空。
     */
    public static function valuesFor(array $rows, string $period): array
    {
        // 見出し（27行目のK列）が「希望」のときだけ書く＝表の形が変わっていたら、ほかの欄を上書きしない。
        if (trim((string) ($rows[self::FIRST_ROW - 2][self::WISH_COL] ?? '')) !== self::HEADER) {
            return [];
        }

        $names = [];
        $last = -1;
        for ($r = self::FIRST_ROW - 1; $r <= self::LAST_ROW - 1; $r++) {
            $name = self::normalize((string) ($rows[$r][self::NAME_COL] ?? ''));
            $names[] = $name;
            if ($name !== '') {
                $last = count($names) - 1;
            }
        }
        if ($last < 0) {
            return [];
        }
        $names = array_slice($names, 0, $last + 1);

        // スタッフを名前で引けるようにする（同じ名前が2人いたら、どちらか分からないので使わない）。
        $byName = [];
        foreach (Person::staff()->get(['id', 'name']) as $p) {
            $key = self::normalize((string) $p->name);
            if ($key === '') {
                continue;
            }
            $byName[$key] = isset($byName[$key]) ? false : (string) $p->id;
        }

        $wish = WishCount::forMonth($period);

        return array_map(function (string $name) use ($byName, $wish) {
            $id = $name === '' ? false : ($byName[$name] ?? false);
            if ($id === false) {
                return '';
            }

            return (string) (int) ($wish[$id]['wish'] ?? 0);
        }, $names);
    }

    /**
     * 「中身が変わったか」の判定から、K列・L列の名簿の範囲を外す。
     * ⚠ 外さないと、ECSが書いた希望数のせいで毎朝「変わった」になり、受信箱に印が付き続ける。
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<int, string>>
     */
    public static function blankOut(array $rows): array
    {
        for ($r = self::FIRST_ROW - 1; $r <= self::LAST_ROW - 1; $r++) {
            if (! isset($rows[$r]) || ! is_array($rows[$r])) {
                continue;
            }
            foreach ([self::WISH_COL, self::RATIO_COL] as $c) {
                if (array_key_exists($c, $rows[$r])) {
                    $rows[$r][$c] = '';
                }
            }
        }

        return $rows;
    }

    /** 名前の空白（半角・全角）を取って比べる。 */
    private static function normalize(string $name): string
    {
        return preg_replace('/[\s　]+/u', '', $name) ?? '';
    }
}
