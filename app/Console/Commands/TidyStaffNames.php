<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\StaffName;
use Illuminate\Console\Command;

/**
 * すでに名簿に入っているスタッフの氏名から、空白を取り除く（2026-08-28 baba要望）。
 *
 * 運用の決まり＝スタッフは苗字と名前をつめる／社員は半角スペースで空ける。
 * 今後の登録は保存のたびに自動でそろう（Person の saving・正本＝App\Support\StaffName）が、
 * **すでに入っている人は自動では直らない**ので、これで一度だけそろえる。
 *
 * 使い方：
 *   php artisan ecs:tidy-staff-names           … 直る人の一覧を見るだけ（何も書き換えない）
 *   php artisan ecs:tidy-staff-names --apply   … 実際に書き換える
 *
 * ⚠ 社員は対象外（空けるのが正しいため）。ローマ字の名前も触らない。
 * ⚠ 氏名を変えても、アサイン・出勤の記録は同じ人（S-###）のままなので消えない。
 */
class TidyStaffNames extends Command
{
    protected $signature = 'ecs:tidy-staff-names
        {--apply : 実際に書き換える（未指定は一覧を見るだけ）}';

    protected $description = 'スタッフの氏名から空白を取り除いて、書き方をそろえる';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $targets = Person::where('role', 'staff')->get()
            ->filter(fn (Person $p) => StaffName::needsTidy($p->name, $p->role));

        if ($targets->isEmpty()) {
            $this->info('空白が入っているスタッフはいません。そろっています。');

            return self::SUCCESS;
        }

        $this->line('■ 空白を取り除くスタッフ：'.$targets->count().'人');
        foreach ($targets as $p) {
            $this->line(sprintf('  %-8s %s  →  %s', $p->id, $p->name, StaffName::tidy($p->name, $p->role)));
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('※ まだ何も書き換えていません。実行するには --apply を付けてください。');
            $this->line('   php artisan ecs:tidy-staff-names --apply');

            return self::SUCCESS;
        }

        // save() のたびに Person の saving が名前をそろえる＝ここでは保存するだけ。
        foreach ($targets as $p) {
            $p->save();
        }

        $this->newLine();
        $this->info($targets->count().'人の氏名から空白を取り除きました。');

        return self::SUCCESS;
    }
}
