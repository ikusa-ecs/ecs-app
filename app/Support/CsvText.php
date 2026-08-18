<?php

namespace App\Support;

/**
 * CSV一括取込の入口で使う「文字コードそろえ」。
 *
 * なぜ必要か（2026-08-18）：
 *   ECSが配るテンプレートはUTF-8だが、**ExcelでCSVを開いて普通に上書き保存すると
 *   Shift_JIS（CP932）に変わってしまう**。そうなると見出しの「コンテンツ名」等が
 *   読めなくなり、中身は入っているのに全行「〇〇が空です」というエラーになる。
 *   社員がExcelで編集するのは避けられないので、読む側で吸収する。
 *
 * やっていること：
 *   ① 先頭のBOM（Excelが付ける見えない目印）を取り除く
 *   ② UTF-8として読めないときだけ、Shift_JIS（またはEUC-JP）とみなしてUTF-8に直す
 *
 * 使う場所＝アップロードされたCSVを読む3か所（名簿・コンテンツ・案件）。
 * 取込を増やすときも、file_get_contents した直後にこれを1回通すだけでよい。
 */
class CsvText
{
    /** アップロードされたCSVの中身を、UTF-8の文字列にそろえて返す。 */
    public static function toUtf8(string $raw): string
    {
        // ① 先頭のBOMを取る
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        // すでにUTF-8として読めるなら、そのまま返す（テンプレをそのまま使った場合はここ）
        if ($raw === '' || mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // ② UTF-8として読めない＝別の文字コード。日本語で実際に来るのはこの2つ。
        foreach (['SJIS-win', 'EUC-JP'] as $encoding) {
            if (mb_check_encoding($raw, $encoding)) {
                return mb_convert_encoding($raw, 'UTF-8', $encoding);
            }
        }

        // どれとも判定できないときは、いちばん多いExcelのShift_JISとみなして変換する。
        // （ここで諦めて返すと、結局「空です」のエラーになって原因が分からないため）
        return mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
    }
}
