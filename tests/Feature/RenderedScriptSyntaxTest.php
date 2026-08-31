<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JsSyntaxCheck;
use Tests\TestCase;

/**
 * 「画面は出るのに JavaScript だけ死んでいる」を見つける（2026-08-31 baba要望）。
 *
 * 【なぜ要るか＝これが繰り返し起きている事故そのもの】
 * Blade の中の JavaScript が壊れると、**HTMLは普通に出るのに JS が丸ごと止まる**。
 *   ・真っ白にならない ・表が空になる ・ボタンを押しても何も起きない
 * これまでのテストは「画面が開くか（200か）」「文字が出ているか」しか見ていなかったので、
 * **全部緑のまま本番へ出てしまう**。実際に 2026-08-26／08-28／08-31 と起きている。
 *
 * ここでは **実際に画面を出して**、その HTML の <script> の中身が
 * 文法として壊れていないかを調べる。Blade の @json・@foreach が展開されたあとの姿を見るので、
 * ファイルの文字だけを見る `BladeScriptEscapeTest` では見つからないものも捕まえられる。
 */
class RenderedScriptSyntaxTest extends TestCase
{
    use RefreshDatabase;

    /** 社員以上で見る画面（サイドバーから開けるもの）。 */
    private const EMPLOYEE_PAGES = [
        '/dashboard', '/projects', '/project-form', '/assign-sheet', '/assign-dashboard',
        '/staff', '/employees', '/masters', '/settings', '/stats', '/imports',
        '/employee-availability', '/finance-list', '/paper-stock', '/mypage', '/profile',
        '/person-import', '/content-import', '/project-import', '/past-import',
        '/availability-import', '/assign-publish', '/entries', '/experience',
    ];

    /** スタッフ本人が見る画面。 */
    private const STAFF_PAGES = ['/staff-portal', '/profile', '/guide-staff'];

    public function test_every_employee_screen_has_working_javascript(): void
    {
        $me = PersonFactory::new()->admin()->create();
        ProjectFactory::new()->count(2)->create();
        PersonFactory::new()->staff()->count(2)->create();

        $this->assertScriptsAreValid($me, self::EMPLOYEE_PAGES);
    }

    public function test_every_staff_screen_has_working_javascript(): void
    {
        $me = PersonFactory::new()->staff()->create();
        ProjectFactory::new()->count(2)->create();

        $this->assertScriptsAreValid($me, self::STAFF_PAGES);
    }

    /** それぞれの画面を出して、<script> の中身を調べる。 */
    private function assertScriptsAreValid($me, array $pages): void
    {
        $bad = [];

        foreach ($pages as $url) {
            $res = $this->actingAsPerson($me)->get($url);
            if ($res->getStatusCode() !== 200) {
                continue;   // 権限や前提データで開かない画面はここでは扱わない
            }

            foreach (JsSyntaxCheck::extractScripts($res->getContent()) as $n => $js) {
                foreach (JsSyntaxCheck::problems($js) as $p) {
                    $bad[] = "{$url} の {$n}個目の<script>： {$p}";
                }
            }
        }

        $this->assertSame([], $bad,
            "画面のJavaScriptが壊れています。\n"
            ."この状態になると、画面は普通に出るのにボタンが効かず、表が空になります（真っ白にならないので目で気づけません）。\n"
            .implode("\n", $bad));
    }
}
