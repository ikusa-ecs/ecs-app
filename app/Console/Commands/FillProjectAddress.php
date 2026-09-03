<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Support\AddressParts;
use Illuminate\Console\Command;

/**
 * 既存の案件の「都道府県・市区町村」を、会場住所（location）から一度だけ埋め直す（2026-09-03）。
 *
 * これから保存する案件は Project の saving で自動で入る。
 * このコマンドは**それより前に登録された案件**のためのもの。
 *
 * ⚠ location から取れたものだけ入れる。取れなければ空のまま（推測で埋めない）。
 * ⚠ 案件の保存イベントを動かさない（＝編集履歴やカレンダー同期の印を付けない）。
 *   住所を直したわけではなく、写しを作っているだけなので、履歴に出すと紛らわしい。
 */
class FillProjectAddress extends Command
{
    protected $signature = 'ecs:fill-project-address {--dry : 書き込まずに、何件変わるかだけ見る}';

    protected $description = '案件の会場住所から都道府県・市区町村を切り出して埋める（体験先さがしの場所検索用）';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $changed = 0;
        $noPref = 0;
        $blank = 0;

        foreach (Project::query()->cursor() as $p) {
            $parts = AddressParts::of($p->location);
            $pref = $parts['prefecture'] !== '' ? $parts['prefecture'] : null;
            $city = $parts['city'] !== '' ? $parts['city'] : null;

            if (trim((string) $p->location) === '') {
                $blank++;
            } elseif ($pref === null) {
                $noPref++;
                $this->line('  都道府県が読めません： '.$p->id.'  '.mb_substr((string) $p->location, 0, 30));
            }

            if ($p->prefecture === $pref && $p->city === $city) {
                continue;
            }
            $changed++;
            if (! $dry) {
                // ⚠ 保存イベントを通さずに書く（編集履歴・カレンダー同期の印を付けないため）。
                Project::withoutEvents(fn () => Project::where('id', $p->id)->update([
                    'prefecture' => $pref,
                    'city' => $city,
                ]));
            }
        }

        $this->info(($dry ? '【見るだけ】' : '').'埋めた（変わった）案件： '.$changed.'件');
        $this->line('会場住所が空の案件： '.$blank.'件（そのまま空にしています）');
        $this->line('都道府県が書かれていない案件： '.$noPref.'件（空のままです。場所で探すときは出てきません）');

        return self::SUCCESS;
    }
}
