<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Project;
use App\Support\DirectorSync;
use Illuminate\Console\Command;

/**
 * D（ディレクター）の「古い保存先」と「新しい保存先」の食い違いを数える。
 *
 * 経緯：Dの保存先が2つに分かれていた（古い＝projects.director_id／新しい＝assignments の role='D'）。
 * 2026-08-06 に保存を一本化したが、**それ以前に登録された案件は食い違いが残っている**。
 * どちらが新しいかの記録が無いため機械的には決められない＝**実データを入れたあと、
 * この件数を見てから baba が決める**（作業リストの未決事項）。
 *
 * 使い方：
 *   php artisan ecs:director-diff              … 件数のまとめだけ
 *   php artisan ecs:director-diff --list       … 食い違っている案件を1行ずつ表示
 *   php artisan ecs:director-diff --fix=assignments --apply
 *        … 「assignments（新しい方）を正」として古い列を上書きする
 *   php artisan ecs:director-diff --fix=column --apply
 *        … 「古い列を正」として assignments 側に写す（D決め画面にも出るようになる）
 *
 * ⚠ --apply を付けないときは何も書き換えない（プレビューのみ）。
 */
class DirectorDiff extends Command
{
    protected $signature = 'ecs:director-diff
        {--list : 食い違っている案件を1件ずつ表示する}
        {--fix= : どちらを正とするか（assignments または column）}
        {--apply : 実際に書き換える（未指定はプレビューのみ）}';

    protected $description = 'Dの保存先（古い列 と assignments）の食い違いを数える／そろえる';

    public function handle(): int
    {
        $fix = (string) $this->option('fix');
        $apply = (bool) $this->option('apply');

        if ($fix !== '' && ! in_array($fix, ['assignments', 'column'], true)) {
            $this->error('--fix には assignments か column を指定してください。');

            return self::FAILURE;
        }

        $names = Person::pluck('name', 'id');

        $same = 0;
        $onlyColumn = [];    // 古い列だけにDがある
        $onlyAssign = [];    // assignments だけにDがある
        $conflict = [];      // 両方あるが別人＝要判断

        foreach (Project::orderBy('start_date')->get() as $p) {
            [$newD] = DirectorSync::current($p);
            $oldD = $p->director_id;

            if ($newD === $oldD) {
                $same++;
                continue;
            }

            $row = [
                'id' => $p->id,
                'date' => $p->start_date?->format('Y-m-d') ?? '(日付なし)',
                'name' => $p->project_name,
                'old' => $oldD ? ($names[$oldD] ?? $oldD) : '—',
                'new' => $newD ? ($names[$newD] ?? $newD) : '—',
                'project' => $p,
            ];

            if ($oldD && ! $newD) {
                $onlyColumn[] = $row;
            } elseif (! $oldD && $newD) {
                $onlyAssign[] = $row;
            } else {
                $conflict[] = $row;
            }
        }

        $this->newLine();
        $this->line('■ Dの保存先の食い違い');
        $this->line('  一致（同じ人／両方なし）          : ' . $same . ' 件');
        $this->line('  案件一覧だけにDがある（古い列のみ）: ' . count($onlyColumn) . ' 件');
        $this->line('  D決め画面だけにDがある（新しい側） : ' . count($onlyAssign) . ' 件');
        $this->line('  両方あるが別人（要判断）           : ' . count($conflict) . ' 件');
        $this->newLine();

        if ($this->option('list')) {
            $all = array_merge(
                array_map(fn ($r) => $r + ['kind' => '古い列だけ'], $onlyColumn),
                array_map(fn ($r) => $r + ['kind' => '新しい側だけ'], $onlyAssign),
                array_map(fn ($r) => $r + ['kind' => '別人（要判断）'], $conflict),
            );
            if ($all) {
                $this->table(
                    ['種別', '案件ID', '開催日', '案件名', '案件一覧のD', 'D決め画面のD'],
                    array_map(fn ($r) => [$r['kind'], $r['id'], $r['date'], mb_strimwidth($r['name'], 0, 30, '…'), $r['old'], $r['new']], $all)
                );
            } else {
                $this->info('食い違っている案件はありません。');
            }
        }

        if ($fix === '') {
            $this->line('※ そろえるときは --fix=assignments（D決め画面を正）または --fix=column（案件一覧を正）を付けてください。');
            $this->line('   --apply を付けるまでは書き換えません。');

            return self::SUCCESS;
        }

        $targets = array_merge($onlyColumn, $onlyAssign, $conflict);
        if (empty($targets)) {
            $this->info('そろえる対象はありません。');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('プレビューです（--apply を付けると ' . count($targets) . ' 件を「'
                . ($fix === 'assignments' ? 'D決め画面（assignments）' : '案件一覧（古い列）') . '」に合わせます）。');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($targets as $r) {
            /** @var Project $p */
            $p = $r['project'];
            [$newD, $newSd] = DirectorSync::current($p);

            if ($fix === 'assignments') {
                // assignments を正＝古い列を上書きする（SDも一緒にそろえる）
                DirectorSync::mirrorToProject($p, $newD, $newSd);
            } else {
                // 古い列を正＝assignments 側へ写す（D決め画面にも出るようになる）
                DirectorSync::apply($p, $p->director_id, $p->sd_id);
            }
            $done++;
        }

        $this->info('✅ ' . $done . ' 件をそろえました（' . $fix . ' を正としました）。');

        return self::SUCCESS;
    }
}
