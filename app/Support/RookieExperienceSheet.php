<?php

namespace App\Support;

use App\Models\ContentDifficulty;
use App\Models\Person;

/**
 * 「新人経験値」シートの取込（2026-10-13 baba「ECSを始める前に経験しているコンテンツをCSVで取り込んで更新したい」）。
 *
 * 元＝スプレッドシート「【東京社員】月別出勤可能日/新人社員管理シート」の「新人経験値」タブ。
 *   見出し行＝分類・コンテンツ名・難易度・必修/推奨…のあとに「氏名」「◯ヵ月」が2列ずつ並ぶ。
 *   その下の行＝「経験あり」「D経験あり」。中身は TRUE / FALSE（チェックボックス）。
 *
 * 入れ先＝新人ページの「経験を手で直す」（people.rookie_overrides {コンテンツID:{fc|d: done|none}}）。
 *   ・TRUE＝「やった」にする（経験あり→fc、D経験あり→d）。
 *   ・FALSE・空＝**触らない**（ECSのアサインから数えた経験や、手で入れた印を消さない）。
 *   ⚠ ECSを始める前の経験はアサインに残っていない＝この取込でしか入らない。
 * コンテンツ＝難易度表（content_difficulties）でつないだ台帳のコンテンツ。上半分はリアル・下半分はオンラインの表で引く。
 *   つながっていない行は使わない（勘でつながない＝RookieDifficulty と同じ考え方）。結果で一覧を出す。
 * 人＝社員の氏名（空白の有無・全角半角を無視）。1人に決まらない名前は使わない。
 */
final class RookieExperienceSheet
{
    /**
     * CSV → 「人の名前 → 種類（リアル／オンライン） → [コンテンツ名 => ['fc'=>bool,'d'=>bool]]」。
     * ⚠ 1枚の中に、上がリアル・「【オンライン】」の見出し行（もう一度「コンテンツ名」がある行）から下がオンライン
     *   （2026-10-13 baba「リアルとオンラインがまとまっているので1枚で登録したい」）。
     *   「消えた資料」「ジャンサバ」などは両方にある＝種類ごとに分けて持つ。人の列は上の見出しのものを使う。
     *
     * @return array<string, array<string, array<string, array{fc:bool, d:bool}>>>
     */
    public static function parse(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $csv);
        rewind($fp);
        $rows = [];
        while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            // ⚠ trim() に全角空白を渡すと日本語が壊れる＝正規表現で外す。
            $rows[] = array_map(fn ($v) => (string) preg_replace('/^[\s　]+|[\s　]+$/u', '', (string) $v), $r);
        }
        fclose($fp);

        // 見出し＝「コンテンツ名」がある行。その次の行に「経験あり」「D経験あり」。
        $start = null;
        $nameCol = null;
        foreach ($rows as $i => $r) {
            $c = array_search('コンテンツ名', $r, true);
            if ($c !== false) {
                $start = $i;
                $nameCol = $c;
                break;
            }
        }
        if ($start === null || ! isset($rows[$start + 1])) {
            return [];
        }
        $head = $rows[$start];
        $sub = $rows[$start + 1];

        // 人の列＝下の行が「経験あり」の列（その右隣が「D経験あり」）。
        $people = [];
        foreach ($sub as $i => $v) {
            if ($v === '経験あり' && ($head[$i] ?? '') !== '') {
                $people[$i] = $head[$i];
            }
        }

        $out = [];
        $kind = RookieDifficulty::REAL;
        for ($i = $start + 2; $i < count($rows); $i++) {
            $name = $rows[$i][$nameCol] ?? '';
            // 2つ目の「コンテンツ名」の行＝ここから下はオンライン。
            if ($name === 'コンテンツ名') {
                $kind = str_contains(implode('', $rows[$i]), 'オンライン') ? RookieDifficulty::ONLINE : RookieDifficulty::REAL;

                continue;
            }
            if ($name === '' || $name === 'ー' || $name === 'D数') {
                continue;
            }
            foreach ($people as $col => $person) {
                $out[$person][$kind][$name] = [
                    'fc' => self::yes($rows[$i][$col] ?? ''),
                    'd' => self::yes($rows[$i][$col + 1] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * 取り込む。
     *
     * @return array{people:int, marks:int, unknownPeople:list<string>, unlinked:list<string>}
     */
    public static function import(string $csv): array
    {
        $parsed = self::parse($csv);

        // 種類 → シートの名前 → 台帳のコンテンツID（難易度表でつないだもの）。
        $link = [];
        foreach (ContentDifficulty::whereNotNull('content_id')->get(['kind', 'sheet_name', 'content_id']) as $d) {
            $link[$d->kind][$d->sheet_name] = $d->content_id;
        }

        $byName = [];
        foreach (Person::employees()->get(['id', 'name']) as $p) {
            $byName[self::key((string) $p->name)][] = $p->id;
        }

        $unknownPeople = [];
        $unlinked = [];
        $people = 0;
        $marks = 0;
        foreach ($parsed as $personName => $byKind) {
            $ids = $byName[self::key($personName)] ?? [];
            if (count($ids) !== 1) {
                $unknownPeople[] = $personName;

                continue;
            }
            $p = Person::find($ids[0]);
            $ov = is_array($p->rookie_overrides) ? $p->rookie_overrides : [];
            $changed = false;
            foreach ($byKind as $kind => $contents) {
                foreach ($contents as $sheetName => $v) {
                    if (! $v['fc'] && ! $v['d']) {
                        continue;
                    }
                    $cid = $link[$kind][$sheetName] ?? null;
                    if ($cid === null) {
                        $unlinked[$sheetName.'（'.$kind.'）'] = true;

                        continue;
                    }
                    foreach (['fc', 'd'] as $k) {
                        if ($v[$k] && ($ov[$cid][$k] ?? null) !== 'done') {
                            $ov[$cid][$k] = 'done';
                            $changed = true;
                            $marks++;
                        }
                    }
                }
            }
            if ($changed) {
                $p->rookie_overrides = $ov;
                $p->save();
            }
            $people++;
        }

        return [
            'people' => $people,
            'marks' => $marks,
            'unknownPeople' => $unknownPeople,
            'unlinked' => array_keys($unlinked),
        ];
    }

    /** チェックが入っているか（TRUE・1・✓・○）。 */
    private static function yes(string $v): bool
    {
        return in_array(mb_strtoupper($v), ['TRUE', '1', '✓', '✔', '○', '〇', '●'], true);
    }

    /** 氏名の比べ方＝空白（全角・半角）を外す。 */
    private static function key(string $name): string
    {
        return (string) preg_replace('/[\s　]+/u', '', $name);
    }
}
