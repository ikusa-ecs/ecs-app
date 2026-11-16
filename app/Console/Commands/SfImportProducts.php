<?php

namespace App\Console\Commands;

use App\Support\SfProductCsv;
use Illuminate\Console\Command;

/**
 * SFの商品一覧CSVを、商品の対応表（sf_products）に取り込む（2026-11-16）。
 * 中身の正本は App\Support\SfProductCsv（画面の取込と同じものを呼ぶ）。
 *
 * ⚠ 同じ商品Idは上書き更新。人が決めた「扱い」「コンテンツ」「メモ」は消さない。
 */
class SfImportProducts extends Command
{
    protected $signature = 'ecs:sf-import-products {path? : CSVのパス（省略時は database/data/sf_products_205.csv）}';

    protected $description = 'SFの商品一覧CSVを商品の対応表に取り込む（人が決めた扱いは消さない）';

    public function handle(): int
    {
        $path = $this->argument('path') ?: SfProductCsv::bundledPath();
        if (! is_file($path)) {
            $this->error("CSVが見つかりません: {$path}");

            return self::FAILURE;
        }

        $r = SfProductCsv::import((string) file_get_contents($path));
        $this->info("新しく入れた商品：{$r['created']}件／更新した商品：{$r['updated']}件");
        foreach ($r['skipped'] as $s) {
            $this->warn('読めなかった行：'.$s);
        }

        return self::SUCCESS;
    }
}
