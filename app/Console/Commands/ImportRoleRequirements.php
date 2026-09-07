<?php

namespace App\Console\Commands;

use App\Support\RoleRequirementCsv;
use Illuminate\Console\Command;

/**
 * 「必要アサイン人数リスト」CSV を読み取り、コンテンツ×規模ごとの必要ポジション枠
 * （content_role_requirements）に取り込む。
 *
 * 既定は dry-run（DBに書かず一覧表示のみ）。--apply で実際に反映する。
 *
 * ⚠ **解析と反映の中身はここに書かない。** 正本は App\Support\RoleRequirementCsv の1か所で、
 *   取込画面（/role-requirement-import）も同じものを呼んでいる。
 *   このコマンドは「サーバーで手で流したいとき」の入口として残してある。
 */
class ImportRoleRequirements extends Command
{
    protected $signature = 'ecs:import-role-requirements {path : 必要アサイン人数.csv のパス} {--apply : 実際にDBへ反映（未指定はプレビューのみ）} {--debug : 解析の内部ログをstderrへ}';

    protected $description = '必要アサイン人数CSVを content_role_requirements へ取り込む（既定はプレビュー）';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("CSVが見つかりません: {$path}");

            return self::FAILURE;
        }

        $debug = $this->option('debug')
            ? function (int $row, string $name) {
                fwrite(STDERR, "EMPTY row={$row} name0=".json_encode($name, JSON_UNESCAPED_UNICODE)."\n");
            }
            : null;

        $rows = RoleRequirementCsv::rows((string) file_get_contents($path));
        $parsed = RoleRequirementCsv::parse($rows, $debug);
        $summary = RoleRequirementCsv::summary($parsed);

        // ── プレビュー表示 ──
        foreach ($summary['items'] as $item) {
            $tag = match ($item['matchType']) {
                'exact'      => "既存({$item['contentId']})",
                'normalized' => "≈既存({$item['contentId']}「{$item['matchedName']}」＝書き方ちがい)",
                'ambiguous'  => "⚠要確認 既存({$item['contentId']}) ほか".(count($item['candidates']) - 1).'件の候補',
                'maybe'      => '⚠要確認 ★新規（似た台帳あり：'
                    .implode('・', array_map(fn ($c) => $c['id'].$c['name'], $item['candidates'])).'）',
                default      => '★新規',
            };
            $this->line("■ {$item['product']}  [{$tag}]");
            foreach ($item['scales'] as $scale => $info) {
                $posSummary = [];
                foreach ($info['byPos'] as $pos => $c) {
                    $posSummary[] = "{$pos}×{$c}";
                }
                $this->line("   {$scale}: ".implode(' ', $posSummary));
                foreach ($info['slots'] as $slot) {
                    $extra = [];
                    if ($slot['note'] !== '') {
                        $extra[] = "備考:{$slot['note']}";
                    }
                    if ($slot['patrol'] !== null) {
                        $extra[] = "巡回:{$slot['patrol']}";
                    }
                    $ex = $extra ? '  ('.implode(' / ', $extra).')' : '';
                    $this->line("      - {$slot['pos']}  ←P:{$slot['rawP']}{$ex}");
                }
            }
        }
        $this->newLine();
        $this->info('コンテンツ数: '.$summary['contentCount'].'（うち新規 '.$summary['newCount'].'） / 枠合計: '.$summary['slotTotal']);

        if (! $this->option('apply')) {
            $this->warn('※ これはプレビューです。DBには何も書き込んでいません。反映するには --apply を付けてください。');

            return self::SUCCESS;
        }

        // ── 反映（--apply）──
        $result = RoleRequirementCsv::apply($parsed);
        $this->info("DBへ反映しました（コンテンツ {$result['contents']}件・うち新規 {$result['new']}件 / 枠 {$result['slots']}件）。");

        return self::SUCCESS;
    }
}
