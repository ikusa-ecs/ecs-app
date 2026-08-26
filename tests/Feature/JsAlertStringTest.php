<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 画面のJSで「confirm( / alert( の文の中に本物の改行が入っていないか」を見張るテスト。
 *
 * ⚠ 2026-08-26 に**本番で案件一覧が真っ白（案件0件）になった**原因がこれ。
 *   JSの決まりで `'..'` の中に改行は書けない。改行が入ると文字列が閉じないので
 *   **その script 全体が動かなくなる**。エラー画面にはならず、画面は開くのに
 *   ボタンが効かない・表が空になるだけなので、目で見ても気づけない。
 *   （改行を入れたいときは `\n` と書く）
 *
 * 機械的に置換すると `\n` が本物の改行に化けることがあるため、ここで見張る。
 */
class JsAlertStringTest extends TestCase
{
    /** confirm( / alert( を含む行は、その行の中でクォートが閉じていること。 */
    public function test_no_alert_string_is_left_open_at_end_of_line(): void
    {
        $bad = [];

        foreach ($this->bladeFiles() as $path) {
            $lines = preg_split('/\R/u', (string) file_get_contents($path));
            foreach ($lines as $i => $line) {
                if (! preg_match('/\b(confirm|alert)\s*\(/', $line)) {
                    continue;
                }
                if ($this->quoteLeftOpen($line)) {
                    $bad[] = basename($path) . ':' . ($i + 1) . '  ' . trim(mb_substr($line, 0, 80));
                }
            }
        }

        $this->assertSame([], $bad, "JSの文字列が行の途中で閉じていません（改行は \\n と書いてください）:\n"
            . implode("\n", $bad));
    }

    /** その行のあと、シングル／ダブルクォートが開いたままか。 */
    private function quoteLeftOpen(string $line): bool
    {
        // 行コメント（// 以降）は落とす。URLの // は消さない。
        $line = (string) preg_replace('#(?<![:/])//.*$#u', '', $line);
        // エスケープされたクォートは数えない。
        $line = str_replace(["\\'", '\\"'], '', $line);

        foreach (["'", '"'] as $q) {
            if (substr_count($line, $q) % 2 === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $dir = resource_path('views');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $out = [];
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
