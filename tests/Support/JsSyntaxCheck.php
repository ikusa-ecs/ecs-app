<?php

namespace Tests\Support;

/**
 * 画面に出たあとの JavaScript が、文法として壊れていないかを調べる（2026-08-31）。
 *
 * 【なぜ要るか＝同じ壊れ方を何度もやっている】
 * Blade の中の JavaScript が壊れると、**画面は普通に出るのに JS だけが丸ごと止まる**。
 *   ・真っ白にならない
 *   ・表が空になる／ボタンを押しても何も起きない
 * ＝ 目で見ても、これまでのテスト（画面が開くか・文字が出ているか）でも気づけない。
 * 2026-08-26／08-28 と本番で起きている。
 *
 * これまでの `BladeScriptEscapeTest` は **ファイルの文字**を見ていたので、
 * Blade の @json や @foreach が展開されたあとの姿は見ていなかった。
 * ここでは **実際に画面へ出た HTML から <script> を取り出して**調べる。
 *
 * 【見るもの】
 *  1. 引用符（' "）の文字列が、閉じないまま行が終わっていないか
 *     ＝ 改行をあらわす2文字が本物の改行に化けたときの形。過去3回これ。
 *  2. かっこ（{} () []）の数が合っているか ＝ 途中で切れた・二重に貼られたときの形。
 *
 * ⚠ 完全な JavaScript の構文解析ではない（Node が無い環境でも動かすため）。
 *   **誤検知で止めない**のを最優先にしている＝「確実に壊れている形」だけを見つける。
 *   そのために、正規表現リテラル（/.../）とテンプレート文字列の ${ } を正しく読み飛ばす。
 *   ⚠ ここを雑に作ると `return /[",\n]/.test(v)` のような普通のコードを
 *     「壊れている」と言い出して、本物の事故が埋もれる。
 */
class JsSyntaxCheck
{
    /** この語のあとの「/」は、割り算ではなく正規表現のはじまり。 */
    private const REGEX_KEYWORDS = [
        'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void',
        'case', 'do', 'else', 'yield', 'await', 'throw',
    ];

    /** この記号のあとの「/」も、正規表現のはじまり。 */
    private const REGEX_AFTER = '(,=:[!&|?{};+-*%~^<>';

    /**
     * HTML から、外部読み込みでない <script> の中身を取り出す。
     *
     * @return list<string>
     */
    public static function extractScripts(string $html): array
    {
        $out = [];
        if (! preg_match_all('~<script\b([^>]*)>(.*?)</script>~is', $html, $m, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $one) {
            // src= で外から読むもの、JSON など JavaScript でないものは対象外。
            if (preg_match('~\bsrc\s*=~i', $one[1])) {
                continue;
            }
            if (preg_match('~\btype\s*=\s*["\']~i', $one[1])
                && ! preg_match('~\btype\s*=\s*["\'](text/javascript|module)["\']~i', $one[1])) {
                continue;
            }
            $out[] = $one[2];
        }

        return $out;
    }

    /**
     * JavaScript を調べて、壊れているところを日本語で返す（問題なければ空の配列）。
     *
     * @return list<string>
     */
    public static function problems(string $js): array
    {
        $problems = [];
        $len = strlen($js);
        $line = 1;
        $prev = '';        // 直前の「意味のある文字」
        $word = '';        // 直前の語（return などを見分ける）
        $depth = ['{' => 0, '(' => 0, '[' => 0];
        $pairs = ['}' => '{', ')' => '(', ']' => '['];

        // テンプレート文字列の中に入ったことを覚えておく積み木。
        // `...${ ここは普通のJS }...` を正しく行き来するために使う。
        $stack = [];   // 'template' を積む。${ に入ったら中かっこの深さを覚える。

        for ($i = 0; $i < $len; $i++) {
            $c = $js[$i];

            if ($c === "\n") {
                $line++;
                $word = '';
                continue;
            }
            if ($c === "\r" || $c === ' ' || $c === "\t") {
                $word = '';
                continue;
            }

            // ── コメント ──
            if ($c === '/' && ($js[$i + 1] ?? '') === '/') {
                while ($i < $len && $js[$i] !== "\n") {
                    $i++;
                }
                $line++;
                $word = '';
                continue;
            }
            if ($c === '/' && ($js[$i + 1] ?? '') === '*') {
                $end = strpos($js, '*/', $i + 2);
                if ($end === false) {
                    $problems[] = "{$line}行目：コメント（/*）が閉じていません。";
                    break;
                }
                $line += substr_count(substr($js, $i, $end - $i), "\n");
                $i = $end + 1;
                $word = '';
                continue;
            }

            // ── 正規表現リテラル ──
            if ($c === '/' && self::looksLikeRegex($js, $i)) {
                $i = self::skipRegex($js, $i);
                $prev = '/';
                $word = '';
                continue;
            }

            // ── 文字列（' "）… 閉じずに行が終わったら壊れている ──
            if ($c === "'" || $c === '"') {
                $i = self::skipQuoted($js, $i, $c, $line, $problems);
                $prev = $c;
                $word = '';
                continue;
            }

            // ── テンプレート文字列のはじまり ──
            if ($c === '`') {
                [$i, $line, $entered] = self::enterTemplate($js, $i, $line, $problems);
                if ($entered) {
                    // `${` で普通のJSに戻った＝そこまでの中かっこの深さを覚えておく
                    $stack[] = $depth['{'];
                    $depth['{']++;
                }
                $prev = '`';
                $word = '';
                continue;
            }

            // ── かっこの数 ──
            if (isset($depth[$c])) {
                $depth[$c]++;
            } elseif (isset($pairs[$c])) {
                $depth[$pairs[$c]]--;
                if ($c === '}' && $stack !== [] && $depth['{'] === end($stack)) {
                    // ${ } の終わり＝テンプレート文字列の続きへ戻る
                    array_pop($stack);
                    [$i, $line, $entered] = self::enterTemplate($js, $i, $line, $problems, true);
                    if ($entered) {
                        $stack[] = $depth['{'];
                        $depth['{']++;
                    }
                    $prev = '`';
                    $word = '';
                    continue;
                }
                if ($depth[$pairs[$c]] < 0) {
                    $problems[] = "{$line}行目：閉じかっこ「{$c}」が多すぎます。";
                    $depth[$pairs[$c]] = 0;
                }
            }

            $word = ctype_alpha($c) || $c === '_' || $c === '$' ? $word.$c : '';
            $prev = $c;
        }

        foreach ($depth as $open => $n) {
            if ($n > 0) {
                $close = array_search($open, $pairs, true);
                $problems[] = "かっこ「{$open}」が {$n} 個閉じられていません（「{$close}」が足りない）。";
            }
        }

        return $problems;
    }

    /**
     * 「/」が正規表現のはじまりか（割り算ではないか）。
     *
     * ⚠ 直前の1文字だけでは決められない＝`return /…/` のように**語のあと**にも来る。
     *   その場で少し前に戻って、直前の記号と語の両方を見る。
     *   ここを間違えると、普通のコードを「壊れている」と言い出して本物の事故が埋もれる。
     */
    private static function looksLikeRegex(string $js, int $i): bool
    {
        // 空白をとばして直前の文字まで戻る
        $j = $i - 1;
        while ($j >= 0 && ($js[$j] === ' ' || $js[$j] === "\t" || $js[$j] === "\r" || $js[$j] === "\n")) {
            $j--;
        }
        if ($j < 0) {
            return true;   // ファイルの先頭
        }

        $prev = $js[$j];

        // 直前が語なら、その語を取り出して調べる（return / typeof / case …）
        if (ctype_alnum($prev) || $prev === '_' || $prev === '$') {
            $end = $j;
            while ($j >= 0 && (ctype_alnum($js[$j]) || $js[$j] === '_' || $js[$j] === '$')) {
                $j--;
            }

            return in_array(substr($js, $j + 1, $end - $j), self::REGEX_KEYWORDS, true);
        }

        return strpos(self::REGEX_AFTER, $prev) !== false;
    }

    /**
     * テンプレート文字列の中身を読み進める。`${` に当たったら普通のJSへ戻る。
     *
     * @param  bool  $resuming  ${ } のあとの続きから読むとき true
     * @return array{0:int, 1:int, 2:bool}  [進めた位置, 行数, ${ で抜けたか]
     */
    private static function enterTemplate(string $js, int $i, int $line, array &$problems, bool $resuming = false): array
    {
        $len = strlen($js);
        $start = $line;

        for ($j = $i + 1; $j < $len; $j++) {
            $c = $js[$j];
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === "\n") {
                $line++;   // テンプレート文字列は行をまたいでよい
                continue;
            }
            if ($c === '$' && ($js[$j + 1] ?? '') === '{') {
                return [$j + 1, $line, true];
            }
            if ($c === '`') {
                return [$j, $line, false];
            }
        }

        $problems[] = "{$start}行目：テンプレート文字列（`）が最後まで閉じていません。";

        return [$len, $line, false];
    }

    /** ' か " の文字列を飛ばす。閉じないまま行が終わったら知らせる。 */
    private static function skipQuoted(string $js, int $i, string $quote, int &$line, array &$problems): int
    {
        $len = strlen($js);
        $start = $line;
        for ($j = $i + 1; $j < $len; $j++) {
            $c = $js[$j];
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === "\n") {
                $problems[] = "{$start}行目：引用符（{$quote}）の文字列が閉じないまま行が終わっています。"
                    .'＝改行をあらわす2文字が本物の改行に化けた可能性。この画面のJavaScriptは丸ごと止まります。';

                return $j - 1;
            }
            if ($c === $quote) {
                return $j;
            }
        }
        $problems[] = "{$start}行目：引用符（{$quote}）の文字列が最後まで閉じていません。";

        return $len;
    }

    /** 正規表現リテラルを飛ばす。 */
    private static function skipRegex(string $js, int $i): int
    {
        $len = strlen($js);
        $inClass = false;
        for ($j = $i + 1; $j < $len; $j++) {
            $c = $js[$j];
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === "\n") {
                return $j - 1;   // 正規表現は行をまたげない＝見立て違い。戻す。
            }
            if ($c === '[') {
                $inClass = true;
            } elseif ($c === ']') {
                $inClass = false;
            } elseif ($c === '/' && ! $inClass) {
                return $j;
            }
        }

        return $len;
    }
}
