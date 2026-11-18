<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\JsSyntaxCheck;
use Tests\TestCase;

/**
 * 「画面は出るのに JavaScript だけ死んでいる」を見つける（2026-08-31 baba要望）。
 *
 * 【なぜ要るか＝これが繰り返し起きている事故そのもの】
 * 画面の中の JavaScript が壊れると、**HTMLは普通に出るのに JS が丸ごと止まる**。
 *   ・真っ白にならない ・表が空になる ・ボタンもタブも押せない
 * これまでのテストは「画面が開くか（200か）」しか見ていなかったので、
 * **全部緑のまま本番へ出てしまう**。2026-08-26／08-28／08-31 と起きている。
 *
 * ⚠ **画面の一覧を手で書かない。** 最初の版は手で並べていたため `/pickup` が漏れ、
 *   その画面が壊れているのに気づけなかった（2026-08-31）。
 *   ルート表から**自動で全部**拾う＝画面を足したら自動で見張りの対象になる。
 */
class RenderedScriptSyntaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_screen_an_admin_can_open_has_working_javascript(): void
    {
        $me = PersonFactory::new()->admin()->create(['office' => '東京']);
        ProjectFactory::new()->count(2)->create(['office' => '東京']);
        PersonFactory::new()->staff()->count(2)->create(['office' => '東京']);

        $this->assertScriptsAreValid($me, $this->screenUrls());
    }

    public function test_every_screen_a_staff_can_open_has_working_javascript(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        ProjectFactory::new()->count(2)->create(['office' => '東京']);

        $this->assertScriptsAreValid($me, $this->screenUrls());
    }

    /**
     * 画面として開ける URL を、ルート表から自動で拾う。
     * ・GET だけ（POSTは画面ではない）
     * ・{id} などの入れ替え部分が無いものだけ（値を勝手に作らない）
     * ・ログインや認証の入口は対象外（画面の中身がほぼ無く、ここでは見なくてよい）
     *
     * @return list<string>
     */
    private function screenUrls(): array
    {
        $skip = ['login', 'logout', 'otp', 'up', 'password/reset', 'password/forgot', 'register'];
        $urls = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (str_contains($uri, '{') || $uri === '/' || str_starts_with($uri, '_')) {
                continue;
            }
            foreach ($skip as $s) {
                if (str_starts_with($uri, $s)) {
                    continue 2;
                }
            }
            $urls[] = '/'.ltrim($uri, '/');
        }

        sort($urls);

        return array_values(array_unique($urls));
    }

    /** Node が使えるか（無い機械ではこのチェックだけ飛ばす＝テストは落とさない）。 */
    private static ?bool $hasNode = null;

    /** 同じ中身の <script>（共通の部品）を何度も調べない。 */
    private static array $nodeSeen = [];

    /**
     * Node の `--check`（実行せず文法だけ見る）で調べる。問題なければ null、壊れていればエラーの1行。
     */
    private function nodeProblem(string $js): ?string
    {
        if (self::$hasNode === null) {
            $out = [];
            $code = 1;
            @exec('node --version 2>&1', $out, $code);
            self::$hasNode = $code === 0;
        }
        if (! self::$hasNode || trim($js) === '') {
            return null;
        }
        $key = md5($js);
        if (array_key_exists($key, self::$nodeSeen)) {
            return self::$nodeSeen[$key];
        }

        $file = tempnam(sys_get_temp_dir(), 'ecsjs');
        $path = $file.'.js';
        @rename($file, $path);
        file_put_contents($path, $js);
        $out = [];
        $code = 0;
        @exec('node --check '.escapeshellarg($path).' 2>&1', $out, $code);
        @unlink($path);

        $msg = null;
        if ($code !== 0) {
            // 「SyntaxError: …」の行と、その場所のコードを1行にまとめる
            $err = array_values(array_filter($out, fn ($l) => str_contains($l, 'SyntaxError')));
            $where = trim((string) ($out[1] ?? ''));
            $msg = ($err[0] ?? 'JavaScriptの文法エラー').'　場所：'.mb_substr($where, 0, 120);
        }

        return self::$nodeSeen[$key] = $msg;
    }

    /** それぞれの画面を出して、<script> の中身を調べる。 */
    private function assertScriptsAreValid($me, array $pages): void
    {
        $bad = [];
        $checked = 0;

        foreach ($pages as $url) {
            $res = $this->actingAsPerson($me)->get($url);
            if ($res->getStatusCode() !== 200) {
                continue;   // 権限や前提データで開かない画面は、ここでは扱わない
            }
            $checked++;

            foreach (JsSyntaxCheck::extractScripts($res->getContent()) as $n => $js) {
                foreach (JsSyntaxCheck::problems($js) as $p) {
                    $bad[] = "{$url} の {$n}個目の<script>： {$p}";
                }
                // Node がある機械では、本物の構文チェックもかける（2026-11-18）。
                // 上の簡易チェックは「引用符とかっこの数」しか見ないので、
                // '…remove('auto-fill')…' のように **数は合っているのに文として壊れている形**を見逃す。
                // 収支入力がこれで 11/16〜11/18 のあいだ本番で動かなかった（案件が選べない）。
                if ($p = $this->nodeProblem($js)) {
                    $bad[] = "{$url} の {$n}個目の<script>（Nodeの構文チェック）： {$p}";
                }
            }
        }

        // 何も見ていないのに緑になるのを防ぐ（ルートの拾い方が壊れたら気づけるように）。
        $this->assertGreaterThan(5, $checked, '画面をほとんど見ていません。ルートの拾い方が壊れていないか確かめてください。');

        $this->assertSame([], $bad,
            "画面のJavaScriptが壊れています。\n"
            ."この状態になると、画面は普通に出るのにボタンもタブも押せず、表が空になります\n"
            ."（真っ白にならないので目で気づけません）。\n"
            .implode("\n", $bad));
    }
}
