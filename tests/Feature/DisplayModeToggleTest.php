<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「🖥 PC表示に切り替える」（表示モードの手動切り替え）のテスト。
 *
 * 決まっていること（2026-08-17 baba：置き場所は左メニューの下＝B案）：
 *  ・ふだんは画面の幅で自動判定。これは「うまく表示できないときの逃げ道」
 *  ・ログイン時には聞かない（ログインしっぱなしで使うので選択がすぐ古くなるため）
 *  ・画面を作り分けるのではなく、表示幅の指定（viewport）を差し替えるだけ
 *    ＝改修の手間が2倍にならない。ここが崩れると設計方針ごと壊れるので見張る。
 */
class DisplayModeToggleTest extends TestCase
{
    use RefreshDatabase;

    /** 切り替えボタンが左メニューの中にある（＝どの画面からでも押せる）。 */
    public function test_the_toggle_lives_in_the_sidebar(): void
    {
        $employee = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($employee)->get('/dashboard')
            ->assertOk()
            ->assertSee('id="pcModeBtn"', false)
            ->assertSee('ECStogglePcMode()', false);
    }

    /**
     * 表示幅の指定を差し替える仕掛けが、画面が描かれる前（head）に入っている。
     * 後から差し替えると、一瞬スマホ表示が見えてからPC表示に変わる＝チラつくため。
     */
    public function test_the_switch_happens_in_the_head_before_render(): void
    {
        $employee = PersonFactory::new()->manager()->create();

        $html = $this->actingAsPerson($employee)->get('/dashboard')->assertOk()->getContent();

        $head = substr($html, 0, (int) strpos($html, '<body'));

        $this->assertStringContainsString('id="ecsViewport"', $head);
        $this->assertStringContainsString('ecs_force_pc', $head);
        $this->assertStringContainsString('width=1200', $head);
    }

    /** ふだん（何も選んでいない状態）の表示幅は、これまでどおり端末に合わせる。 */
    public function test_the_default_is_still_automatic(): void
    {
        $employee = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($employee)->get('/dashboard')
            ->assertOk()
            ->assertSee('content="width=device-width, initial-scale=1.0"', false);
    }

    /** ログイン画面では聞かない（選択がすぐ古くなるので、ログイン時に聞かない方針）。 */
    public function test_the_login_screen_does_not_ask(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('pcModeBtn', false);
    }
}
