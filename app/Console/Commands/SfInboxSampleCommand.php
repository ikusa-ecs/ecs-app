<?php

namespace App\Console\Commands;

use App\Support\SfInboxSample;
use Illuminate\Console\Command;

/**
 * SFの受信箱に見本データを入れる／消す（2026-11-16）。SFにつなぐまで画面を試すためのもの。
 *
 *   php artisan ecs:sf-sample          … 見本4件を入れる（すでにあれば入れ直さない）
 *   php artisan ecs:sf-sample --clear  … 見本だけ消す（本物の商談には触らない）
 *
 * ⚠ 見本は商談Idが「006SAMPLE」で始まり、名前に【見本】が付く（is_sample=true）。
 */
class SfInboxSampleCommand extends Command
{
    protected $signature = 'ecs:sf-sample {--clear : 見本だけ消す}';

    protected $description = 'SFの受信箱に見本データを入れる（--clear で見本だけ消す）';

    public function handle(): int
    {
        if ($this->option('clear')) {
            $n = SfInboxSample::clear();
            $this->info("見本を{$n}件消しました（見本から登録した案件は残っています。要らなければ案件一覧で消してください）。");

            return self::SUCCESS;
        }

        $n = SfInboxSample::seed();
        $this->info("見本を{$n}件入れました。画面：/sf-inbox");

        return self::SUCCESS;
    }
}
