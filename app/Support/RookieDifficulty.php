<?php

namespace App\Support;

use App\Models\ContentDifficulty;
use App\Models\Project;

/**
 * コンテンツの難易度と新人の必修・推奨（2026-10-09 baba・新人ページ）。
 *
 * 元＝スプレッドシート「コンテンツ難易度」（リアル／オンラインの2枚）をCSVで取り込む。
 *   列＝分類・コンテンツ名・難易度（1〜4）・新人D必修・新人D推奨（オンラインは必修だけ）。
 *   印＝●（そのコンテンツ）／◻︎ ▲ ★（同じ分類の中から1つでよい）。
 * ⚠ シートの名前とECSの台帳の名前は違うことが多い＝取込で名前が合ったものだけ自動でつなぎ、
 *   残りは新人ページで台帳のどれかを選んでつなぐ。つながっていない行は使わない（勘でつながない）。
 * ⚠ 案件がオンラインならオンラインの表、それ以外はリアルの表を見る（同じ名前でも難易度が違う）。
 */
final class RookieDifficulty
{
    public const REAL = 'リアル';

    public const ONLINE = 'オンライン';

    /** 「同じ分類の中から1つ」の印。 */
    private const ONE_OF_MARKS = ['◻', '▲', '★', '□'];

    /**
     * CSVの中身 → 行の一覧。
     *
     * @return list<array{sheet_name:string, category:?string, difficulty:?int, must:bool, recommend:bool, one_of:bool}>
     */
    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $csv);
        rewind($fp);
        $rows = [];
        while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            // ⚠ trim() に全角空白を渡すとバイト単位で削って日本語が壊れる＝正規表現で外す。
            $rows[] = array_map(fn ($v) => (string) preg_replace('/^[\s　]+|[\s　]+$/u', '', (string) $v), $r);
        }
        fclose($fp);

        // 見出しの行＝「コンテンツ名」があるところ。
        $nameCol = null;
        $start = null;
        foreach ($rows as $i => $r) {
            $c = array_search('コンテンツ名', $r, true);
            if ($c !== false) {
                $nameCol = $c;
                $start = $i;
                break;
            }
        }
        if ($nameCol === null) {
            return [];
        }

        $head = $rows[$start];
        $mustCol = null;
        $recCol = null;
        foreach ($head as $i => $h) {
            $h = str_replace(["\n", "\r", ' '], '', $h);
            if ($mustCol === null && str_contains($h, '新人') && str_contains($h, '必修')) {
                $mustCol = $i;
            }
            if ($recCol === null && str_contains($h, '新人') && str_contains($h, '推奨')) {
                $recCol = $i;
            }
        }

        $out = [];
        $category = null;
        for ($i = $start + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $name = $r[$nameCol] ?? '';
            if (($r[0] ?? '') === '達成目安' || $name === '' || $name === 'ー') {
                continue;
            }
            if ($name === 'D数') {
                break;
            }
            $cat = $nameCol > 0 ? ($r[$nameCol - 1] ?? '') : '';
            if ($cat !== '') {
                $category = $cat;
            }
            $diff = $r[$nameCol + 1] ?? '';
            $mustMark = $mustCol !== null ? ($r[$mustCol] ?? '') : '';
            $recMark = $recCol !== null ? ($r[$recCol] ?? '') : '';
            $out[] = [
                'sheet_name' => $name,
                'category' => $category,
                'difficulty' => ctype_digit($diff) ? (int) $diff : null,
                'must' => $mustMark !== '',
                'recommend' => $recMark !== '',
                'one_of' => self::isOneOf($mustMark) || self::isOneOf($recMark),
            ];
        }

        return $out;
    }

    /**
     * 取り込む（同じ種類・同じ名前は上書き）。手でつないだ content_id は消さない。
     *
     * @return array{saved:int, linked:int, unlinked:int}
     */
    public static function import(string $csv, string $kind): array
    {
        $contents = new ImportContents;
        $saved = 0;
        foreach (self::parse($csv) as $row) {
            $rec = ContentDifficulty::firstOrNew(['kind' => $kind, 'sheet_name' => $row['sheet_name']]);
            $rec->fill($row);
            if (! $rec->content_id) {
                $rec->content_id = self::guess($contents, $row['sheet_name']);
            }
            $rec->save();
            $saved++;
        }
        $all = ContentDifficulty::where('kind', $kind);

        return [
            'saved' => $saved,
            'linked' => (clone $all)->whereNotNull('content_id')->count(),
            'unlinked' => (clone $all)->whereNull('content_id')->count(),
        ];
    }

    /** 名前から台帳のコンテンツを1つだけ見つけられたらそのID（2つ以上・0なら null＝人が選ぶ）。 */
    private static function guess(ImportContents $contents, string $name): ?string
    {
        foreach ([$name, trim((string) preg_replace('/[（(][^）)]*[）)]/u', '', $name))] as $try) {
            if ($try === '') {
                continue;
            }
            $ids = $contents->resolve($try)['ids'];
            if (count($ids) === 1) {
                return $ids[0];
            }
        }

        return null;
    }

    private static function isOneOf(string $mark): bool
    {
        foreach (self::ONE_OF_MARKS as $m) {
            if ($mark !== '' && mb_strpos($mark, $m) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 案件はどちらの表を見るか（オンラインならオンライン）。 */
    public static function kindOf(Project $p): string
    {
        return str_contains((string) ($p->format ?? ''), 'オンライン') ? self::ONLINE : self::REAL;
    }

    /**
     * つないだ行を「種類 → コンテンツID → 行の一覧」で引けるようにする（1回だけ読む）。
     *
     * @return array<string, array<string, list<ContentDifficulty>>>
     */
    public static function map(): array
    {
        $out = [];
        foreach (ContentDifficulty::whereNotNull('content_id')->get() as $d) {
            $out[$d->kind][$d->content_id][] = $d;
        }

        return $out;
    }

    /** 案件の難易度＝つないだコンテンツのうち一番むずかしいもの（分からなければ null）。 */
    public static function ofProject(Project $p, array $map): ?int
    {
        $kind = self::kindOf($p);
        $best = 0;
        foreach (is_array($p->content_ids) ? $p->content_ids : [] as $cid) {
            // 同じコンテンツに複数の行（チャンバラ 企業／英語 など）がつながっていたら、やさしいほう。
            $best = max($best, self::easiest($map, $kind, (string) $cid));
        }

        return $best ?: null;
    }

    private static function easiest(array $map, string $kind, string $cid): int
    {
        $list = $map[$kind][$cid] ?? ($map[$kind === self::REAL ? self::ONLINE : self::REAL][$cid] ?? []);
        $vals = array_filter(array_map(fn ($d) => $d->difficulty, $list), fn ($v) => $v !== null);

        return $vals ? min($vals) : 0;
    }
}
