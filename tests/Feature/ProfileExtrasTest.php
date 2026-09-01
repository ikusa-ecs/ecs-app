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

    // ─────────────────────────────────────────────────────────────
    // ここから：マイページのカードでその場で直せる欄（2026-08-31 baba要望）
    //
    // ⚠ なぜ作ったか＝6項目を足したものの、入れる場所が「マイページ →『プロフィールを編集』
    //   → 下までスクロール」しか無く、**社員が気づけなかった**（babaから指摘）。
    // ─────────────────────────────────────────────────────────────

    /** マイページを開いた時点で、6項目が見えて・その場で直せる。 */
    public function test_the_mypage_shows_the_items_and_can_edit_them_there(): void
    {
        $me = PersonFactory::new()->create();

        $this->actingAsPerson($me)->get('/mypage')
            ->assertOk()
            ->assertSee('できること・やってみたいこと')
            ->assertSee('チャレンジしたいポジション')
            ->assertSee('日常で使っているオンラインツール')
            // その場で保存できる＝別画面へ飛ばさない
            ->assertSee('action="/profile/extras"', false);
    }

    /** マイページの欄から保存できる。 */
    public function test_the_mypage_form_saves_the_items(): void
    {
        $me = PersonFactory::new()->create();

        $this->actingAsPerson($me)->post('/profile/extras', [
            'driving_level' => 'ハイエースも普通サイズも運転可能',
            'english_level' => '日常会話レベル',
            'other_languages' => '中国語（日常会話）',
            'challenge_positions_sent' => '1',
            'challenge_positions' => ['MC（司会進行）'],
            'online_tools_sent' => '1',
            'online_tools' => ['Zoom', 'Notion'],
            'online_tools_other' => 'Miro',
            'profile_note' => '土日はほぼ空いています',
        ])->assertRedirect();

        $me->refresh();
        $this->assertSame('ハイエースも普通サイズも運転可能', $me->driving_level);
        $this->assertSame('日常会話レベル', $me->english_level);
        $this->assertSame(['MC（司会進行）'], $me->challenge_positions);
        $this->assertSame(['Zoom', 'Notion'], $me->online_tools);
        $this->assertSame('Miro', $me->online_tools_other);
        $this->assertSame('土日はほぼ空いています', $me->profile_note);
    }

    /**
     * ⚠⚠ マイページの欄は**氏名・身長などを消してはいけない**。
     *   /profile は「フォームに出ている項目を全部書き換える」作りなので、
     *   もし送り先を間違えて /profile にすると、氏名も身長も空になる。
     */
    public function test_the_mypage_form_does_not_wipe_the_other_profile_fields(): void
    {
        $me = PersonFactory::new()->create([
            'name' => '試験 太郎',
            'name_kana' => 'しけん たろう',
            'height' => '172',
            'shoe_size' => '27',
            'prefecture' => '東京都',
            'nearest_station' => '新宿',
            'department' => 'イベプラ',
        ]);

        $this->actingAsPerson($me)->post('/profile/extras', [
            'driving_level' => '普通サイズなら運転可能',
        ])->assertRedirect();

        $me->refresh();
        $this->assertSame('試験 太郎', $me->name);
        $this->assertSame('しけん たろう', $me->name_kana);
        $this->assertSame('172', $me->height);
        $this->assertSame('27', $me->shoe_size);
        $this->assertSame('東京都', $me->prefecture);
        $this->assertSame('新宿', $me->nearest_station);
        $this->assertSame('イベプラ', $me->department);
        $this->assertSame('普通サイズなら運転可能', $me->driving_level);
    }

    /**
     * 送られてこなかった欄は消さない。
     * ⚠ 画面によって出している項目が違うので、ここが緩いと**別の画面で入れた内容が消える**。
     */
    public function test_fields_that_were_not_sent_are_left_alone(): void
    {
        $me = PersonFactory::new()->create([
            'english_level' => 'ビジネス会話可能レベル',
            'online_tools' => ['Zoom'],
            'profile_note' => '前に書いたこと',
        ]);

        // 運転だけ送る。
        $this->actingAsPerson($me)->post('/profile/extras', ['driving_level' => '普通サイズなら運転可能']);

        $me->refresh();
        $this->assertSame('ビジネス会話可能レベル', $me->english_level, '送っていない欄が消えています。');
        $this->assertSame(['Zoom'], $me->online_tools, '送っていないチェック欄が消えています。');
        $this->assertSame('前に書いたこと', $me->profile_note);
    }

    /** マイページからもチェックを全部外せる（印つきで空を送る）。 */
    public function test_unchecking_everything_from_the_mypage_clears_it(): void
    {
        $me = PersonFactory::new()->create(['online_tools' => ['Zoom', 'Slack']]);

        $this->actingAsPerson($me)->post('/profile/extras', ['online_tools_sent' => '1']);

        $me->refresh();
        $this->assertNull($me->online_tools);
    }

    /**
     * ⚠ 見張り：保存のしかたを画面ごとに書き写さない。
     *   運転／英語を people の列へ直接入れているのは ProfileExtras だけであること。
     *   （書き写すと、片方の決まりだけ直したときに画面によって保存結果が変わる）
     */
    public function test_only_one_place_writes_these_columns(): void
    {
        $offenders = [];
        foreach (['app/Http/Controllers', 'app/Support'] as $dir) {
            foreach (glob(base_path($dir).'/*.php') as $file) {
                if (basename($file) === 'ProfileExtras.php') {
                    continue;   // ここが正本。
                }
                $code = (string) file_get_contents($file);
                if (preg_match('~->(driving_level|english_level|challenge_positions|online_tools)\s*=~u', $code)) {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame([], $offenders,
            '本人の申告の保存が正本の外に書かれています： '.implode(' / ', $offenders)
            .'。App\Support\ProfileExtras::apply() を呼んでください。');
    }
}
