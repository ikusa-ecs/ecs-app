<?php

namespace App\Console\Commands;

use App\Support\SfPull;
use Illuminate\Console\Command;

/**
 * SFの商談を読んで受信箱に入れる（2026-11-20）。ふだんは5分おきに自動で動く（routes/console.php）。
 *
 *   php artisan ecs:sf-pull        … 前に読んだ後に変わった商談だけ読む
 *   php artisan ecs:sf-pull --all  … 全部読み直す（つないだ直後の確認・取りこぼしが心配なとき）
 *
 * ⚠ 受信箱に入れるだけ。案件は作らない・変えない（正本＝App\Support\SfPull）。
 * ⚠ 鍵（SF_URL / SF_CLIENT_ID / SF_CLIENT_SECRET）が入っていないあいだは、何もしないで終わる。
 */
class SfPullCommand extends Command
{
    protected $signature = 'ecs:sf-pull {--all : 前回の時刻を無視して全部読み直す}';

    protected $description = 'SFの商談を読んで受信箱に入れる（案件は作らない）';

    public function handle(): int
    {
        $r = SfPull::run((bool) $this->option('all'));

        if ($r['status'] === 'error') {
            $this->error($r['message']);

            return self::FAILURE;
        }
        $this->info($r['message']);

        return self::SUCCESS;
    }
}
