<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 画面のJSで escAttr() / escHtml() を「呼んでいるのに定義していない」画面が無いこと。
 *
 * ⚠ 2026-09-28 に実際に事故になった（baba報告）。
 *   日別ボード（assign.blade.php）の**派遣の行**を作るところが escAttr() を呼んでいたが、
 *   この画面には escHtml() しか無かった。そのため
 *     **派遣依頼が1件でもある案件のカードを作るところで画面が止まり、
 *       その案件から後ろの案件が全部消える**
 *   という見え方になった（「案件一覧にはあるのに日別ボードにだけ出ない」）。
 *   同じ日の他の案件は先に描き終わっていたので出ていて、原因が分かりにくかった。
 *
 * ⚠ JS は自動テストで動かせない（＝押して動くかは人が確かめるしかない）ので、
 *   せめて「呼んでいるのに定義が無い」という**文字で分かる抜け**だけは、ここで止める。
 *
 * ⚠ 増やし方＝関数を増やしたら下の HELPERS に1行足すだけ。
 *   画面を増やしたときは何もしなくてよい（resources/views を全部見るため）。
 */
class ViewJsEscHelpersTest extends TestCase
{
    /** 見張る関数の名前。呼んでいる画面には、必ず同じ画面に定義が要る。 */
    private const HELPERS = ['escAttr', 'escHtml'];

    public function test_esc_ヘルパーを呼んでいる画面には定義がある(): void
    {
        $missing = [];

        foreach ($this->bladeFiles() as $path) {
            $src = (string) file_get_contents($path);

            foreach (self::HELPERS as $fn) {
                if (! $this->calls($src, $fn)) {
                    continue;
                }
                if ($this->defines($src, $fn)) {
                    continue;
                }
                $missing[] = $this->relative($path).' … '.$fn.'() を呼んでいるのに定義がない';
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['画面のJSで、定義していない関数を呼んでいます（その画面は途中で止まります）：'],
            $missing,
            ['', '直し方＝その画面の escHtml() のとなりに、同じ関数を足してください。']
        )));
    }

    /** その関数を呼んでいるか。⚠ escAttrText など、名前が前方一致する別の関数は数えない。 */
    private function calls(string $src, string $fn): bool
    {
        return (bool) preg_match('/(?<![A-Za-z0-9_])'.preg_quote($fn, '/').'\s*\(/', $src);
    }

    /** その関数を定義しているか。function 宣言でも const/let/var への代入でもよい。 */
    private function defines(string $src, string $fn): bool
    {
        $name = preg_quote($fn, '/');

        return (bool) preg_match('/function\s+'.$name.'\s*\(/', $src)
            || (bool) preg_match('/(?<![A-Za-z0-9_])'.$name.'\s*=\s*(function|\()/', $src);
    }

    /** resources/views の下の .blade.php を全部。 */
    private function bladeFiles(): array
    {
        $dir = resource_path('views');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $files = [];
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}
