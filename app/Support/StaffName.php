<?php

namespace App\Support;

/**
 * 氏名の書き方をそろえる正本（2026-08-28 baba要望）。
 *
 * 【運用の決まり（baba）】
 *  ・スタッフ … 苗字と名前の間を**詰める**（例：山田太郎）
 *  ・社員     … 半角スペースで**空ける**（例：山田 太郎）
 *
 * なぜ要るか＝スタッフが自分でフォームに書いた名前をそのまま登録すると、
 * 空白が入ったり入らなかったりして名簿の見た目がそろわない。
 *
 * ⚠ ローマ字の名前は詰めない（John Smith → JohnSmith は読めない）。
 *   日本語の名前だけを詰める。
 * ⚠ 照合（アサイン表の取込・同姓同名の判定）は元から空白を無視して見ているので、
 *   詰めても「名簿に見つからない」は起きない（PersonLookup::normName / SpotStaff::findByName）。
 */
final class StaffName
{
    /** 保存する形にそろえた氏名を返す。社員・ローマ字の名前はそのまま。 */
    public static function tidy(?string $name, ?string $role): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        if ($name === '' || $role !== 'staff') {
            return $name;
        }

        // ローマ字（英字）を含む名前は触らない＝詰めると読めなくなるため。
        if (preg_match('/[A-Za-z]/', $name)) {
            return $name;
        }

        // 半角・全角の空白をすべて取り除く。
        return (string) preg_replace('/[\s　]+/u', '', $name);
    }

    /** そろえる必要があるか（そのまま保存してよいなら false）。 */
    public static function needsTidy(?string $name, ?string $role): bool
    {
        return $name !== null && $name !== self::tidy($name, $role);
    }
}
