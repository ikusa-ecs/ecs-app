<?php

namespace Tests\Feature;

use App\Support\Themes;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 画面の色（テーマ）を人ごとに選べることを守るテスト（2026-09-09 baba要望
 * 「カラーリングを個人によって変更することって可能？」）。
 *
 * ⚠ ここで守りたいのは4つ。
 *   ① 選んだ色はアカウントに残る（端末が変わっても同じ色）。
 *   ② 変えられるのは**自分のぶんだけ**。
 *   ③ 知らない値が来ても画面が崩れない（既定に戻す）。
 *   ④ **色そのものはCSSの1か所だけ**に書く（PHPやBladeに色を書き写すと必ず食い違う）。
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    /** 選んだ色が保存され、画面の <html> に出る。 */
    public function test_a_person_can_choose_a_theme(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false]);

        $this->actingAsPerson($me)->post('/theme', ['theme' => 'orange'])->assertRedirect();

        $this->assertSame('orange', $me->fresh()->theme);

        $this->actingAsPerson($me->fresh())->get('/mypage')
            ->assertOk()
            ->assertSee('data-theme="orange"', false);
    }

    /** 何も選んでいない人は、いままでの色のまま。 */
    public function test_default_is_the_current_look(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get('/mypage')
            ->assertOk()
            ->assertSee('data-theme=""', false);
    }

    /** ⚠ 知らない値は既定に寄せる（URLを書き換えられても画面が崩れないように）。 */
    public function test_unknown_theme_falls_back_to_default(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false, 'theme' => 'orange']);

        $this->actingAsPerson($me)->post('/theme', ['theme' => 'むらさき'])->assertRedirect();

        $this->assertSame(Themes::DEFAULT, $me->fresh()->theme);
    }

    /** ⚠ 他人の色は変えられない（人のIDを受け取らない作りであること）。 */
    public function test_it_only_changes_your_own_theme(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false]);
        $other = PersonFactory::new()->create(['must_onboard' => false, 'theme' => null]);

        // 人のIDを一緒に送ってみても、変わるのは自分のぶんだけ。
        $this->actingAsPerson($me)->post('/theme', ['theme' => 'orange', 'id' => $other->id, 'person' => $other->id]);

        $this->assertSame('orange', $me->fresh()->theme);
        $this->assertNull($other->fresh()->theme, '他人の色を変えてしまっている');
    }

    /** ログインしていない人は選べない（ログインの門の中にある）。 */
    public function test_guests_cannot_change_a_theme(): void
    {
        // ⚠ 保存されず、ログインしていない人はそのまま追い返される（302）。
        $this->post('/theme', ['theme' => 'orange'])->assertStatus(302);
        $this->assertDatabaseMissing('people', ['theme' => 'orange']);
    }


    /**
     * 黒ベースは「見本」だけ＝**保存できない**（2026-09-09）。
     * ⚠ 保存できてしまうと、まだ整えていない画面が白いまま残った状態で固定される。
     */
    public function test_dark_cannot_be_saved(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false]);

        $this->actingAsPerson($me)->post('/theme', ['theme' => 'dark'])->assertRedirect();

        $this->assertSame(Themes::DEFAULT, $me->fresh()->theme, '見本の色を保存してしまっている');
    }

    /** URLに ?theme=dark を付けた画面だけ黒で出る（見本）。保存はされない。 */
    public function test_dark_can_be_previewed_from_the_url(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get(Themes::PREVIEW_PATH . '?theme=dark')
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            // ⚠ 見本だと必ず分かるようにする（保存されたと勘違いさせない）。
            ->assertSee('見本を表示しています');

        // ⚠ 見ただけでは何も保存されない（列は未設定のまま＝null）。
        $this->assertNotSame('dark', $me->fresh()->theme, '見ただけで保存してしまっている');
        $this->assertSame(Themes::DEFAULT, Themes::of($me->fresh()));
    }

    /** ⚠ 知らない色をURLに書かれても、見本にはしない（既定のまま）。 */
    public function test_unknown_preview_is_ignored(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get('/dashboard?theme=むらさき')
            ->assertOk()
            ->assertSee('data-theme=""', false);
    }

    /**
     * ⚠ 色そのものはCSSの1か所（public/ecs/style.css）に書く。
     *   PHP（Themes）に色コードを書き写していないことを見張る。
     */
    public function test_colors_live_only_in_the_css(): void
    {
        $css = (string) file_get_contents(public_path('ecs/style.css'));
        $php = (string) file_get_contents(app_path('Support/Themes.php'));

        $this->assertStringContainsString('html[data-theme="orange"]', $css, 'テーマの色がCSSから消えている');
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}/', $php,
            'App\Support\Themes に色コードを書いてはいけない（色はCSSの1か所だけ）');
    }

    /** スタッフ画面にも同じ色が効く（設定タブから選べる）。 */
    public function test_the_staff_screen_uses_the_same_theme(): void
    {
        $staff = PersonFactory::new()->staff()->create(['must_onboard' => false, 'theme' => 'orange']);

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('data-theme="orange"', false)
            ->assertSee('画面の色');
    }
}
