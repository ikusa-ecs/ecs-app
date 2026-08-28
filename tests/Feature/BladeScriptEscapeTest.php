<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 画面（Blade）の中のJavaScriptで、**文字の中の改行の書き方が実際の改行に化けていないか**を見張る。
 *
 * 【なぜ要るか＝実際に3回やらかしている】
 * 置き換えの途中で `\n`（改行をあらわす2文字）が **本物の改行** になってしまうと、
 * JavaScript は文字列の途中で行が変わったと見なして**そこで止まる**。
 * すると **その画面のJavaScriptが丸ごと動かなくなる**：
 *   ・真っ白にはならない（HTMLは出る）
 *   ・表が空になる／ボタンを押しても何も起きない
 * ＝ 見た目では気づけない。2026-08-28 に「スタッフ名簿が誰も出ない」で本番に出てしまった。
 *
 * 【見つけ方】
 * 化けた行は必ず「開きのカッコやカンマのすぐ後ろで引用符が開いたまま行が終わる」形になる。
 *   例）  ].join('        ← ここで行が終わっている＝化けている
 *          ');
 * ふつうに書けば、開いた引用符は同じ行で閉じる。
 */
class BladeScriptEscapeTest extends TestCase
{
    public function test_no_quoted_string_is_left_open_at_end_of_line(): void
    {
        $bad = [];

        foreach (glob(resource_path('views/*.blade.php')) as $file) {
            $lines = preg_split('/\r?\n/', (string) file_get_contents($file));

            foreach ($lines as $i => $line) {
                // 「( や , や = のあと、引用符が開いたまま行が終わっている」形だけを見る。
                if (preg_match('/[\(\[,=]\s*[\'"]\s*$/', $line)) {
                    $bad[] = basename($file).' の '.($i + 1).'行目： '.trim($line);
                }
            }
        }

        $this->assertSame([], $bad,
            "JavaScriptの文字列が行の途中で切れています（\n が本物の改行に化けた可能性）。\n"
            .'この形になると、その画面のJavaScriptが丸ごと動かなくなります（表が空になる・ボタンが効かない）。');
    }
}
