<?php

namespace Tests\Feature;

use App\Support\StaffLinks;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ画面の「便利リンク集」（settings.staff_links）のテスト。
 *
 * 決まっていること：
 *  ・登録・編集は共通設定（/settings）から社員が行う＝URLが変わってもコードを直さない
 *  ・スタッフ画面の「設定」タブに一覧で出る／1件も無ければカードごと出さない
 *  ・URLは http/https だけ通す（javascript: 等をスタッフ画面に出さないため）
 */
class StaffLinksTest extends TestCase
{
    use RefreshDatabase;

    /** 共通設定から保存すると、その並び順のままスタッフ画面に出る。 */
    public function test_links_saved_from_settings_appear_on_the_staff_portal(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => [
                ['title' => 'スタッフNotion', 'url' => 'https://example.com/notion', 'memo' => 'マニュアルはこちら'],
                ['title' => 'アンケートフォーム', 'url' => 'https://example.com/form', 'memo' => ''],
            ]])
            ->assertOk()
            ->assertJsonPath('links.0.title', 'スタッフNotion')
            ->assertJsonPath('links.1.title', 'アンケートフォーム');

        $staff = PersonFactory::new()->staff()->create();
        $res = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk();

        $res->assertSee('スタッフNotion');
        $res->assertSee('マニュアルはこちら');
        $res->assertSee('https://example.com/form');

        // 並び順は登録したとおり（Notion → フォーム）。
        $links = $res->original->getData()['staffLinks'];
        $this->assertSame(['スタッフNotion', 'アンケートフォーム'], array_column($links, 'title'));
    }

    /**
     * 1件も登録が無いときは、リンク行を出さず「まだ登録がありません」と理由を出す。
     * （黙って空にすると「壊れている」と思われるため。）
     */
    public function test_an_explanation_is_shown_when_there_are_no_links(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $res = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk();

        $res->assertDontSee('class="lk-item"', false);
        $res->assertSee('まだリンクが登録されていません');
        $this->assertSame([], $res->original->getData()['staffLinks']);
    }

    /** リンク集は専用タブ（tab-links）として独立している＝設定タブの中に埋もれていない。 */
    public function test_links_have_their_own_tab(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('data-tab="tab-links"', false)
            ->assertSee('id="tab-links"', false);
    }

    /** http/https 以外のURLは保存を拒否する（スタッフ画面にそのままリンクとして出るため）。 */
    public function test_non_http_urls_are_rejected(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => [
                ['title' => 'あぶないリンク', 'url' => 'javascript:alert(1)'],
            ]])
            ->assertStatus(422);

        $this->assertSame([], StaffLinks::all());
    }

    /** 名前が空の行も保存できない（スタッフ側で何のリンクか分からなくなるため）。 */
    public function test_a_link_without_a_title_is_rejected(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => [
                ['title' => '', 'url' => 'https://example.com'],
            ]])
            ->assertStatus(422);
    }

    /**
     * 長さオーバーで弾いたときは、理由が分かる日本語を返す。
     * （以前は理由に関係なく「名前とURLを入れてください」と返していたので、
     *   「ひとこと説明が長すぎる」で失敗しても原因不明の保存エラーに見えた。）
     */
    public function test_a_too_long_memo_says_why(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $res = $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => [
                [
                    'title' => 'スタッフNotion',
                    'url' => 'https://example.com/notion',
                    'memo' => str_repeat('あ', StaffLinks::MAX_MEMO + 1),
                ],
            ]])
            ->assertStatus(422);

        $this->assertStringContainsString('ひとこと説明', $res->json('message'));
        $this->assertStringContainsString((string) StaffLinks::MAX_MEMO, $res->json('message'));
        $this->assertSame([], StaffLinks::all());
    }

    /** 名前が長すぎる場合も、名前が原因だと分かるメッセージになる。 */
    public function test_a_too_long_title_says_why(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $res = $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => [
                ['title' => str_repeat('あ', StaffLinks::MAX_TITLE + 1), 'url' => 'https://example.com'],
            ]])
            ->assertStatus(422);

        $this->assertStringContainsString('表示する名前', $res->json('message'));
    }

    /** 上限ちょうどは通る（境界で1文字ずれていないことの確認）。 */
    public function test_the_limit_itself_is_accepted(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => [
                [
                    'title' => str_repeat('あ', StaffLinks::MAX_TITLE),
                    'url' => 'https://example.com',
                    'memo' => str_repeat('い', StaffLinks::MAX_MEMO),
                ],
            ]])
            ->assertOk();

        $this->assertCount(1, StaffLinks::all());
    }

    /** 空の配列を保存すると、リンク集がまるごと空になる（全部消す操作）。 */
    public function test_saving_an_empty_list_clears_the_links(): void
    {
        StaffLinks::save([['title' => '消える', 'url' => 'https://example.com']]);
        $this->assertCount(1, StaffLinks::all());

        $manager = PersonFactory::new()->manager()->create();
        $this->actingAsPerson($manager)
            ->postJson('/settings/staff-links', ['links' => []])
            ->assertOk();

        $this->assertSame([], StaffLinks::all());
    }

    /** 保存値が壊れていても画面が落ちない（空一覧として扱う）。 */
    public function test_broken_saved_value_is_treated_as_empty(): void
    {
        \App\Models\Setting::put('staff_links', 'これはJSONではない');

        $this->assertSame([], StaffLinks::all());
    }
}
