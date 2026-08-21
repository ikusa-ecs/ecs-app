<?php

namespace App\Support;

/**
 * クライアント名（企業名）の書き方をそろえる（2026-08-21 baba）。
 *
 * なぜ必要か：
 *   システムは「企業名の文字が完全に一致するか」でお客様を見分けている
 *   （リピート判定 /clients/lookup・案件一覧のリピートバッジ・過去のアサイン履歴）。
 *   そのため「〇〇株式会社」と「〇〇株式会社様」は別のお客様になってしまい、履歴が分かれる。
 *
 * ここでやること：
 *   ・前後の空白（半角・全角）を落とす
 *   ・末尾の敬称（様・御中・さま・サマ）を落とす
 *   ※ 画面では入力欄の右に「様」を固定表示しているので、敬称は保存しない。
 *
 * 入口は案件登録フォームとCSV取込の2か所。増やすときも必ずここを通す。
 */
class ClientName
{
    /** 末尾から落とす敬称。長いものから順に見る。 */
    private const HONORIFICS = ['御中', 'さま', 'サマ', '様'];

    /** 保存用に整える。空になったら null（未入力あつかい）。 */
    public static function normalize(?string $name): ?string
    {
        $s = self::trimSpaces((string) $name);

        // 末尾の敬称を落とす（「様」「 様」「　様」いずれも）。二重敬称も落とせるよう繰り返す。
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::HONORIFICS as $h) {
                if ($s !== $h && str_ends_with($s, $h)) {
                    $s = self::trimSpaces(mb_substr($s, 0, mb_strlen($s) - mb_strlen($h)));
                    $changed = true;
                }
            }
        }

        return $s !== '' ? $s : null;
    }

    /**
     * 前後の空白（半角・全角）を落とす。
     * ⚠ PHPの trim($s, '　') は「文字」ではなく「バイト」で削るため、
     *   全角スペースを指定すると日本語の1文字目を壊すことがある
     *   （実際に「〇〇株式会社」が「?〇株式会社」になった）。必ずこの関数を使う。
     */
    private static function trimSpaces(string $s): string
    {
        return preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $s) ?? $s;
    }
}
