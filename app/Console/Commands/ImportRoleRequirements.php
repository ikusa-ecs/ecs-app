<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Support\AssignmentRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 「必要アサイン人数リスト」CSV を読み取り、コンテンツ×規模ごとの必要ポジション枠
 * （content_role_requirements）に取り込む。
 *
 * 既定は dry-run（DBに書かず一覧表示のみ）。--apply で実際に反映する。
 *
 * 【CSVの構造】コンテンツ1ブロックにつき、参加人数の3段階（～49名／50～99名／100～150名）が
 *   横並び。各段の列： NO / 名前 / P(ポジション) / 巡回 / 備考 / サイズ。
 *   ・規模の対応： ～49名=小型／50～99名=中型／100～150名=大型
 *   ・「軍＝チーム」＝規模で決まるものとして、チャンバラ系の 2軍/3軍/4軍 は
 *     規模の対角線で取る（2軍→小型／3軍→中型／4軍→大型）。
 *   ・備考の 軍師/サポ→SP・チェッカー→CK・受付→RP はアプリの既存ポジションに置換。
 *     それ以外（ディーラー/ミッション/採点/巡回/役名 等）と巡回数は note / patrol にそのまま保持。
 */
class ImportRoleRequirements extends Command
{
    protected $signature = 'ecs:import-role-requirements {path : 必要アサイン人数.csv のパス} {--apply : 実際にDBへ反映（未指定はプレビューのみ）} {--debug : 解析の内部ログをstderrへ}';

    protected $description = '必要アサイン人数CSVを content_role_requirements へ取り込む（既定はプレビュー）';

    /** 参加人数ラベル → 規模。判定順に注意（100を先に見る＝150の"50"に引っかからないため）。 */
    private function scaleOf(string $label): ?string
    {
        if (mb_strpos($label, '100') !== false) {
            return '大型';
        }
        if (mb_strpos($label, '49') !== false || mb_strpos($label, '～4') !== false) {
            return '小型';
        }
        if (mb_strpos($label, '50') !== false) {
            return '中型';
        }
        return null;
    }

    /** 軍数 → 規模（対角線）。 */
    private function scaleForGun(int $gun): ?string
    {
        return [2 => '小型', 3 => '中型', 4 => '大型'][$gun] ?? null;
    }

    /** P（ポジション）＋備考 → アプリの役割コード。 */
    private function mapPosition(string $p, string $biko): string
    {
        $p = trim($p);
        $b = trim($biko);

        if ($p === 'D' || $p === 'D/OP') {
            return AssignmentRole::D;
        }
        if ($p === 'MC') {
            return AssignmentRole::MC;
        }
        if ($p === 'OP') {
            return AssignmentRole::OP;
        }
        // FC は備考で細分：軍師/サポ→SP・チェッカー→CK・受付→RP・それ以外→FC
        if ($p === 'FC') {
            if (mb_strpos($b, 'チェッカー') !== false) {
                return AssignmentRole::CK;
            }
            if (mb_strpos($b, '受付') !== false) {
                return AssignmentRole::RP;
            }
            foreach (['軍師', 'サポ', 'サブ'] as $kw) {
                if (mb_strpos($b, $kw) !== false) {
                    return AssignmentRole::SP;
                }
            }
            return AssignmentRole::FC;
        }
        // 想定外の P はそのまま（正規コードでなければ FC 扱い）
        return AssignmentRole::isValid($p) ? $p : AssignmentRole::FC;
    }

    /** コンテンツ名セル（複数行・箇条書き・注記混在）→ 商品名の配列。 */
    private function parseProducts(string $cell): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/u', $cell) as $line) {
            $line = trim($line);
            // ※注記が始まったら、それ以降は全て説明文（複数行）＝商品名ではないので打ち切る。
            if (mb_substr($line, 0, 1) === '※') {
                break;
            }
            if ($line === '') {
                continue;
            }
            // 「（2軍）」「（3競技想定）」のような注記行だけは除く
            if (preg_match('/^（.*）$/u', $line)) {
                continue;
            }
            // 先頭の「・」や空白（全角含む）を除く。※ltrim(char list) はバイト単位で
            // カタカナ等の先頭バイトを壊すため使わない（マルチバイト安全に preg で除去）。
            $line = preg_replace('/^[・　\s]+/u', '', $line);
            // 末尾の（…）注記を落とす
            $line = preg_replace('/（[^）]*）\s*$/u', '', $line);
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** コンテンツ名セルから軍数（2/3/4）を拾う。無ければ 0。 */
    private function parseGun(string $cell): int
    {
        if (preg_match('/(\d)\s*軍/u', $cell, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("CSVが見つかりません: {$path}");
            return self::FAILURE;
        }

        // escape="" が肝：既定のバックスラッシュ escape だと複数行セル（コンテンツ名の
        // 箇条書き＋注記）を誤って結合／分割してブロックが欠落する。空にすると標準CSVとして正しく読める。
        $fh = fopen($path, 'r');
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        $n = count($rows);

        // 3段（規模）の列位置： [NO, P, 巡回, 備考] と、規模ラベルの列。
        $brackets = [
            ['label' => 1,  'p' => 5,  'patrol' => 6,  'biko' => 7],
            ['label' => 11, 'p' => 15, 'patrol' => 16, 'biko' => 17],
            ['label' => 21, 'p' => 25, 'patrol' => 26, 'biko' => 27],
        ];

        // product => scale => [ ['pos'=>,'note'=>,'patrol'=>], ... ]
        $out = [];

        $cell = fn (array $row, int $c) => trim((string) ($row[$c] ?? ''));
        $rowEmpty = function (array $row) {
            foreach ($row as $v) {
                if (trim((string) $v) !== '') {
                    return false;
                }
            }
            return true;
        };

        $i = 0;
        while ($i < $n) {
            $row = $rows[$i];
            $name0 = $cell($row, 0);
            $hasLabel = mb_strpos($cell($row, 1), '参加人数') !== false
                || mb_strpos($cell($row, 11), '参加人数') !== false
                || mb_strpos($cell($row, 21), '参加人数') !== false;
            $nextIsNo = ($i + 1 < $n) && $cell($rows[$i + 1], 1) === 'NO';
            $isHeader = $name0 !== '' && ($hasLabel || $nextIsNo);

            if (! $isHeader) {
                $i++;
                continue;
            }

            $products = $this->parseProducts($name0);
            $gun = $this->parseGun($name0);
            if ($this->option('debug') && empty($products)) {
                fwrite(STDERR, "EMPTY row={$i} name0=" . json_encode($name0, JSON_UNESCAPED_UNICODE) . "\n");
            }
            // 各段の規模（ラベルから）。ラベルが無いブロックは「規模非依存」＝全規模に適用。
            $scaleByBracket = [];
            $anyLabel = false;
            foreach ($brackets as $bi => $b) {
                $s = $this->scaleOf($cell($row, $b['label']));
                $scaleByBracket[$bi] = $s;
                if ($s !== null) {
                    $anyLabel = true;
                }
            }

            // NOヘッダー行を探し、その次からデータ。
            $j = $i + 1;
            while ($j < $n && $cell($rows[$j], 1) !== 'NO') {
                $j++;
            }
            if ($j >= $n) {
                break;
            }
            $k = $j + 1;

            // このブロックのデータを段ごとに集める。
            $blockSlots = [0 => [], 1 => [], 2 => []];
            while ($k < $n) {
                $r = $rows[$k];
                if ($cell($r, 0) !== '') {
                    break;   // 次ブロック（または「全コンテンツ共通」）
                }
                if ($rowEmpty($r)) {
                    break;   // 空行＝ブロック終わり
                }
                foreach ($brackets as $bi => $b) {
                    $p = $cell($r, $b['p']);
                    if ($p === '') {
                        continue;   // その段は空きスロット（人数パディング）
                    }
                    $biko = $cell($r, $b['biko']);
                    $patrolRaw = $cell($r, $b['patrol']);
                    $blockSlots[$bi][] = [
                        'pos'    => $this->mapPosition($p, $biko),
                        'note'   => $biko,
                        'patrol' => is_numeric($patrolRaw) ? (int) $patrolRaw : null,
                        'rawP'   => $p,
                    ];
                }
                $k++;
            }

            // 段→規模を確定し、product×scale へ格納。
            foreach ($brackets as $bi => $b) {
                if (empty($blockSlots[$bi])) {
                    continue;
                }
                $scales = [];
                if (! $anyLabel) {
                    // ラベル無し＝規模非依存 → 小中大すべてに同じ枠を入れる
                    $scales = ['小型', '中型', '大型'];
                } else {
                    $s = $scaleByBracket[$bi];
                    if ($s === null) {
                        continue;
                    }
                    // 軍がある（チャンバラ系）→ 対角線のみ採用
                    if ($gun > 0 && $this->scaleForGun($gun) !== $s) {
                        continue;
                    }
                    $scales = [$s];
                }
                foreach ($products as $prod) {
                    foreach ($scales as $s) {
                        foreach ($blockSlots[$bi] as $slot) {
                            $out[$prod][$s][] = $slot;
                        }
                    }
                }
            }

            $i = $k;
        }

        // ── プレビュー表示 ──
        $existing = Content::pluck('id', 'content_name');   // 名前→id
        $newCount = 0;
        $slotTotal = 0;
        ksort($out);
        foreach ($out as $prod => $byScale) {
            $exists = isset($existing[$prod]);
            if (! $exists) {
                $newCount++;
            }
            $tag = $exists ? "既存({$existing[$prod]})" : '★新規';
            $this->line("■ {$prod}  [{$tag}]");
            foreach (['小型', '中型', '大型'] as $s) {
                if (empty($byScale[$s])) {
                    continue;
                }
                // ポジション別の合計も出す
                $byPos = [];
                foreach ($byScale[$s] as $slot) {
                    $byPos[$slot['pos']] = ($byPos[$slot['pos']] ?? 0) + 1;
                    $slotTotal++;
                }
                $posSummary = [];
                foreach ($byPos as $pos => $c) {
                    $posSummary[] = "{$pos}×{$c}";
                }
                $this->line("   {$s}: " . implode(' ', $posSummary));
                foreach ($byScale[$s] as $slot) {
                    $extra = [];
                    if ($slot['note'] !== '') {
                        $extra[] = "備考:{$slot['note']}";
                    }
                    if ($slot['patrol'] !== null) {
                        $extra[] = "巡回:{$slot['patrol']}";
                    }
                    $ex = $extra ? '  (' . implode(' / ', $extra) . ')' : '';
                    $this->line("      - {$slot['pos']}  ←P:{$slot['rawP']}{$ex}");
                }
            }
        }
        $this->newLine();
        $this->info('コンテンツ数: ' . count($out) . '（うち新規 ' . $newCount . '） / 枠合計: ' . $slotTotal);

        if (! $this->option('apply')) {
            $this->warn('※ これはプレビューです。DBには何も書き込んでいません。反映するには --apply を付けてください。');
            return self::SUCCESS;
        }

        // ── 反映（--apply）──
        $this->applyToDb($out, $existing);
        return self::SUCCESS;
    }

    /** プレビューと同じ構造を実際にDBへ反映する。 */
    private function applyToDb(array $out, $existing): void
    {
        // 新規コンテンツID採番の起点（CT-\d+ の最大＋1）。
        $maxNum = 0;
        foreach (Content::pluck('id') as $id) {
            if (preg_match('/^CT-(\d+)$/', $id, $m)) {
                $maxNum = max($maxNum, (int) $m[1]);
            }
        }

        DB::transaction(function () use ($out, $existing, &$maxNum) {
            foreach ($out as $prod => $byScale) {
                $contentId = $existing[$prod] ?? null;
                if ($contentId === null) {
                    $maxNum++;
                    $contentId = 'CT-' . str_pad((string) $maxNum, 3, '0', STR_PAD_LEFT);
                    Content::create([
                        'id'           => $contentId,
                        'content_name' => $prod,
                        'active'       => true,
                    ]);
                }

                // このコンテンツの既存必要人数を消して入れ直す（再実行で重複しない）。
                ContentRoleRequirement::where('content_id', $contentId)->delete();

                foreach (['小型', '中型', '大型'] as $s) {
                    if (empty($byScale[$s])) {
                        continue;
                    }
                    $order = 0;
                    foreach ($byScale[$s] as $slot) {
                        ContentRoleRequirement::create([
                            'content_id' => $contentId,
                            'scale'      => $s,
                            'position'   => $slot['pos'],
                            'count'      => 1,
                            'note'       => $slot['note'] !== '' ? $slot['note'] : null,
                            'patrol'     => $slot['patrol'],
                            'sort_order' => $order++,
                        ]);
                    }
                }
            }
        });

        $this->info('DBへ反映しました。');
    }
}
