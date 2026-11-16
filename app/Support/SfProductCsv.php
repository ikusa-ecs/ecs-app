<?php

namespace App\Support;

use App\Models\SfProduct;

/**
 * SFの商品一覧CSV（Id,Name,ProductCode,Family,IsActive,コンテンツ以外の可能性）を
 * 対応表（sf_products）に取り込む正本（2026-11-16）。画面のCSVアップロードと artisan コマンドの両方がここを呼ぶ。
 *
 * 【決まり】
 *   ・鍵は商品Id（18桁）。同じIdがあれば上書き更新（名前・ファミリ・有効か・候補メモ）。
 *   ・⚠ 人が決めた「扱い」「コンテンツ」「メモ」は取り込み直しても消さない。
 *   ・最終列に何か書いてある行（「△機材の可能性」など）は、hint（候補）に写すだけ。
 *     ⚠ 勝手に「無視」に決めない（あくまで推測なので、決めるのは人）。
 *   ・CSVから消えた商品も消さない（過去の商談に付いていることがあるため）。
 */
final class SfProductCsv
{
    /** リポジトリに置いてある205件のCSV（2026-09 時点のSFの全商品）。 */
    public static function bundledPath(): string
    {
        return database_path('data/sf_products_205.csv');
    }

    /**
     * CSVの本文を取り込む。
     *
     * @return array{created: int, updated: int, skipped: list<string>}
     *   skipped＝読めなかった行の説明（黙って落とさない）
     */
    public static function import(string $raw): array
    {
        $rows = CsvText::rows(CsvText::toUtf8($raw));
        $created = 0;
        $updated = 0;
        $skipped = [];

        if ($rows === []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => ['CSVが空です。']];
        }

        // 見出し → 列の位置（並びが変わっても読めるように名前で探す）
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows));
        $col = array_flip($header);
        foreach (['Id', 'Name'] as $need) {
            if (! isset($col[$need])) {
                return ['created' => 0, 'updated' => 0, 'skipped' => ["見出しに「{$need}」の列がありません（SFの商品一覧のCSVか確かめてください）。"]];
            }
        }
        // 最終列＝「コンテンツ以外の可能性」。見出しの名前は長いので、Id〜IsActive 以外の最後の列とみなす。
        $hintCol = null;
        $lastIndex = count($header) - 1;
        if ($lastIndex >= 0 && ! in_array($header[$lastIndex], ['Id', 'Name', 'ProductCode', 'Family', 'IsActive'], true)) {
            $hintCol = $lastIndex;
        }

        $get = fn (array $row, ?int $i) => ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';

        $line = 1;
        foreach ($rows as $row) {
            $line++;
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // 空行
            }
            $id = $get($row, $col['Id']);
            $name = $get($row, $col['Name']);
            // SFのIdは英数字18桁（15桁の古い形も来ることがあるので、それは断る＝鍵がずれるため）
            if (! preg_match('/\A[A-Za-z0-9]{18}\z/', $id)) {
                $skipped[] = "{$line}行目：商品Idが18桁ではありません（{$id}）";

                continue;
            }
            if ($name === '') {
                $skipped[] = "{$line}行目：商品名が空です（{$id}）";

                continue;
            }

            $active = strtoupper($get($row, $col['IsActive'] ?? null));
            $values = [
                'name' => $name,
                'family' => $get($row, $col['Family'] ?? null) ?: null,
                'is_active' => $active === '' ? true : in_array($active, ['TRUE', '1', 'YES'], true),
                'hint' => $get($row, $hintCol) ?: null,
            ];

            $existing = SfProduct::where('sf_product_id', $id)->first();
            if ($existing) {
                // ⚠ handling / content_id / note は触らない（人が決めたもの）
                $existing->fill($values);
                if ($existing->isDirty()) {
                    $existing->save();
                }
                $updated++;
            } else {
                SfProduct::create($values + [
                    'sf_product_id' => $id,
                    'handling' => SfProduct::PENDING,
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
