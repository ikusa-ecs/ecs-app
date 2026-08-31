<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Blade の書き方が、処理されないまま画面に出ていないかを見張る（2026-08-31）。
 *
 * 【なぜ要るか＝本番でスタッフ画面が丸ごと止まった】
 * 画面ファイルには `@verbatim`（＝書いたものをそのまま出す区間）がある。
 * JavaScript のテンプレート文字列（`${…}`）を守るために使っているもの。
 * その中に `@json(...)` と書くと、Blade は処理せず
 *      options:@json(\App\Support\ProfileOptions::drivingChoices())
 * という文字がそのまま JavaScript に出る。JavaScript に「@」や「\」は書けないので
 * **文法エラー**になり、その画面の <script> が丸ごと読み込みに失敗する
 * ＝ 案件一覧が空・タブもボタンも押せない・でも画面は普通に出るので気づけない。
 * （2026-08-31 に本番のスタッフ画面で実際に起きた）
 *
 * 【どうやって見つけるか】
 * **Blade 自身にコンパイルさせて、処理されずに残った命令を探す。**
 * 文字を数えて `@verbatim` の中かどうかを判定する方法は当てにならない
 * （コメントの中で「@verbatim」に触れているだけの行があるため）。
 * Blade が処理したかどうかは、Blade に聞くのがいちばん確実。
 */
class BladeDirectiveLeakTest extends TestCase
{
    /**
     * そのまま出てしまうと画面が壊れる命令。
     * ⚠ `{{ }}` は入れない＝JavaScript の中で使いたくて @verbatim にしていることがあるため
     *   （それが @verbatim を使う本来の目的）。
     */
    private const MUST_NOT_LEAK = [
        '@json(', '@php', '@if(', '@foreach(', '@include(', '@csrf', '@auth', '@can(',
        '@endif', '@endforeach', '@endphp',
    ];

    public function test_no_blade_directive_leaks_into_the_page(): void
    {
        $bad = [];

        foreach ($this->bladeFiles() as $file) {
            $source = (string) file_get_contents($file);

            // Blade にコンパイルさせる。処理された命令は PHP に変わって消える。
            $compiled = Blade::compileString($source);

            foreach (self::MUST_NOT_LEAK as $directive) {
                // @@json のように「わざと文字として出す」書き方は対象外。
                $pattern = '/(?<!@)'.preg_quote($directive, '/').'/';
                if (! preg_match($pattern, $compiled)) {
                    continue;
                }
                $line = $this->firstLineIn($source, $directive);
                $bad[] = basename($file).($line ? " の {$line}行目あたり" : '')."： 「{$directive}」が処理されずに残っています";
            }
        }

        $this->assertSame([], $bad,
            "Bladeの書き方が、処理されないまま画面に出ています。\n"
            ."原因はほぼ必ず「@verbatim（そのまま出す区間）の中に書いてしまった」ことです。\n"
            ."そのまま出ると、その画面のJavaScriptが丸ごと読み込みに失敗し、\n"
            ."ボタンもタブも押せなくなります（画面は普通に出るので目で気づけません）。\n"
            ."直し方＝サーバーの値は @verbatim の外で window.ECS_… に入れ、中では window から読む。\n"
            .implode("\n", $bad));
    }

    /** 見張りが本当に効くか（わざと壊したものを見つけられるか）。 */
    public function test_it_catches_a_directive_written_inside_verbatim(): void
    {
        $broken = "@verbatim\n<script>\n  const a = @json(\$x);\n</script>\n@endverbatim\n";

        $this->assertStringContainsString('@json(', Blade::compileString($broken),
            '@verbatim の中の @json が残ることを前提にした見張りです。前提が崩れました。');
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** その命令が最初に出てくる行番号（人が探しやすいように）。 */
    private function firstLineIn(string $source, string $directive): ?int
    {
        foreach (explode("\n", $source) as $i => $line) {
            if (str_contains($line, $directive)) {
                return $i + 1;
            }
        }

        return null;
    }
}
