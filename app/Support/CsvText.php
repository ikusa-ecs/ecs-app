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

    /**
     * CSVの本文 → 行（各行は列の配列）。
     *
     * ⚠ 1行ずつ str_getcsv に渡す書き方はしないこと。
     *   セルの中に改行が入っていると（備考欄などでよくある）行がずれ、
     *   まったく別の項目を読んでしまう（2026-08-25 に実際に踏んだ）。
     *   ここでは fgetcsv を使う＝引用符で囲まれた中の改行を正しく1つのセルとして扱う。
     *
     * @return list<list<string>>
     */
    public static function rows(string $raw): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, self::toUtf8($raw));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            // 完全な空行（全部 null / 空）は飛ばす
            if ($row === [null] || $row === ['']) {
                continue;
            }
            $rows[] = array_map(fn ($v) => (string) ($v ?? ''), $row);
        }
        fclose($handle);

        return $rows;
    }
}
