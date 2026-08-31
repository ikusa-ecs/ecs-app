<?php

namespace Tests\Feature;

use App\Support\ProfileOptions;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マイプロフィールに増やした項目のテスト（2026-08-31 baba要望）。
 *
 * 見張るところ：
 *  1. 社員もスタッフも、同じ6項目を /profile から保存できる
 *     （運転・英語はもともとスタッフ画面にしか入力欄が無かった＝社員から入らないと意味がない）
 *  2. 選択肢に無い値は入れない（勝手に近い値へ寄せない）
 *  3. スタッフ画面の設定タブからも同じ列に保存できる／チェックを全部外せば消える
 *  4. 名簿の詳細に本人の申告が渡っている（見えないと集めた意味がない）
 */
class ProfileExtrasTest extends TestCase
{
    use RefreshDatabase;

    /** 保存に必要な最低限＋今回の6項目をまとめた入力。 */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => '試験 太郎',
            'driving_level' => 'ハイエースも普通サイズも運転可能',
            'english_level' => '日常会話レベル',
            'other_languages' => '中国語（日常会話）',
            'challenge_positions' => ['MC（司会進行）', '軍師・サポーター'],
            'online_tools' => ['Zoom', 'Slack', 'Notion'],
            'online_tools_other' => 'Miro',
            'profile_note' => '土日はほぼ空いています',
        ], $over);
    }

    /** 社員も /profile から6項目を保存できる。 */
    public function test_employee_can_save_the_new_profile_items(): void
    {
        $me = PersonFactory::new()->create();

        $this->actingAsPerson($me)->post('/profile', $this->payload())
            ->assertRedirect();

        $me->refresh();
        $this->assertSame('ハイエースも普通サイズも運転可能', $me->driving_level);
        $this->assertSame('日常会話レベル', $me->english_level);
        $this->assertSame('中国語（日常会話）', $me->other_languages);
        $this->assertSame(['MC（司会進行）', '軍師・サポーター'], $me->challenge_positions);
        $this->assertSame(['Zoom', 'Slack', 'Notion'], $me->online_tools);
        $this->assertSame('Miro', $me->online_tools_other);
        $this->assertSame('土日はほぼ空いています', $me->profile_note);
    }

    /** スタッフも同じように /profile から保存できる。 */
    public function test_staff_can_save_the_new_profile_items(): void
    {
        $me = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($me)->post('/profile', $this->payload())
            ->assertRedirect();

        $me->refresh();
        $this->assertSame('日常会話レベル', $me->english_level);
        $this->assertSame(['Zoom', 'Slack', 'Notion'], $me->online_tools);
    }

    /**
     * 選択肢に無い値は入れない。
     * ⚠ ここが緩いと、打ち間違いや古い言い方がそのまま記録に残り、あとで数えられなくなる。
     */
    public function test_values_outside_the_list_are_not_saved(): void
    {
        $me = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($me)->post('/profile', $this->payload([
            'driving_level' => 'トラックも運転できる',      // 一覧に無い
            'english_level' => ProfileOptions::NONE,        // 「（なし）」＝未選択
            'challenge_positions' => ['MC（司会進行）', '社長'],
            'online_tools' => ['Zoom', 'マイナーツール'],
        ]))->assertRedirect();

        $me->refresh();
        $this->assertNull($me->driving_level);
        $this->assertNull($me->english_level);
        $this->assertSame(['MC（司会進行）'], $me->challenge_positions);
        $this->assertSame(['Zoom'], $me->online_tools);
    }

    /** スタッフ画面の設定タブからも同じ列に保存できる。 */
    public function test_staff_portal_settings_tab_saves_the_same_columns(): void
    {
        $me = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($me)->postJson('/staff-portal/profile', [
            'other_languages' => '韓国語（片言）',
            'challenge_positions_sent' => '1',
            'challenge_positions' => ['D（ディレクター）'],
            'online_tools_sent' => '1',
            'online_tools' => ['チャットワーク'],
            'online_tools_other' => 'Figma',
            'profile_note' => 'よろしくお願いします',
        ])->assertOk()->assertJson(['ok' => true]);

        $me->refresh();
        $this->assertSame('韓国語（片言）', $me->other_languages);
        $this->assertSame(['D（ディレクター）'], $me->challenge_positions);
        $this->assertSame(['チャットワーク'], $me->online_tools);
        $this->assertSame('Figma', $me->online_tools_other);
    }

    /**
     * チェックを全部外して保存すると消える。
     * ⚠ 「選んだものだけ送る」作りなので、印（_sent）が無いと外した状態を保存できない
     *   ＝一度入れたら二度と消せない画面になる。
     */
    public function test_unchecking_everything_clears_the_saved_choices(): void
    {
        $me = PersonFactory::new()->staff()->create([
            'challenge_positions' => ['MC（司会進行）'],
            'online_tools' => ['Zoom'],
        ]);

        $this->actingAsPerson($me)->postJson('/staff-portal/profile', [
            'challenge_positions_sent' => '1',
            'online_tools_sent' => '1',
        ])->assertOk();

        $me->refresh();
        $this->assertNull($me->challenge_positions);
        $this->assertNull($me->online_tools);
    }

    /** 名簿の詳細（スタッフ・社員）に本人の申告が渡っている。 */
    public function test_the_rosters_receive_the_self_declared_items(): void
    {
        $admin = PersonFactory::new()->create(['permission' => 'admin']);
        $staff = PersonFactory::new()->staff()->create([
            'name' => '名簿 花子',
            'online_tools' => ['Zoom'],
            'profile_note' => '土日は空いています',
        ]);
        $emp = PersonFactory::new()->create([
            'other_languages' => 'フランス語',
        ]);

        // スタッフ名簿
        $res = $this->actingAsPerson($admin)->get('/staff')->assertOk();
        $row = collect($res->viewData('people'))->firstWhere('id', $staff->id);
        $this->assertSame(['Zoom'], $row['profile']['tools']);
        $this->assertSame('土日は空いています', $row['profile']['note']);

        // 社員名簿
        $res = $this->actingAsPerson($admin)->get('/employees')->assertOk();
        $row = collect($res->viewData('employees'))->firstWhere('id', $emp->id);
        $this->assertSame('フランス語', $row['selfProfile']['otherLang']);
    }

    /**
     * マイプロフィールの画面が実際に開いて、増えた欄が出ている。
     * ⚠ 保存が通っても入力欄が出ていなければ誰も入れられない＝画面も見る。
     */
    public function test_the_profile_page_shows_the_new_fields(): void
    {
        $me = PersonFactory::new()->create();

        $this->actingAsPerson($me)->get('/profile')
            ->assertOk()
            ->assertSee('チャレンジしたいポジション')
            ->assertSee('日常で使っているオンラインツール')
            ->assertSee('その他話せる言語')
            ->assertSee('その他備考')
            ->assertSee('ハイエースも普通サイズも運転可能')   // 運転の選択肢
            ->assertSee('ビジネス会話可能レベル')             // 英語の選択肢
            ->assertSee('Notion');                            // ツールの選択肢
    }

    /** スタッフ画面の設定タブにも増えた欄が出ている。 */
    public function test_the_staff_portal_shows_the_new_fields(): void
    {
        $me = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($me)->get('/staff-portal')
            ->assertOk()
            ->assertSee('チャレンジしたいポジション')
            ->assertSee('日常で使っているオンラインツール')
            ->assertSee('その他話せる言語');
    }

    /**
     * 運転・英語の選択肢が画面に直書きで戻っていないか見張る。
     * ⚠ 過去に「同じ選択肢を2か所に書き写して片方だけ直す」事故を何度も起こしている。
     */
    public function test_the_choice_lists_come_from_one_place(): void
    {
        $blade = file_get_contents(resource_path('views/staff_portal.blade.php'));

        $this->assertStringContainsString('ProfileOptions::drivingChoices()', $blade);
        $this->assertStringNotContainsString("'ハイエースも普通サイズも運転可能'", $blade);
        $this->assertStringNotContainsString("'ビジネス会話可能レベル'", $blade);
    }
}
