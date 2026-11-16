<?php

namespace Tests\Feature;

use App\Support\ChatworkMentions;
use App\Support\ChatworkRooms;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * チャットワークの知らせで「誰にメンションするか」を選べること（2026-09-17 baba要望）。
 *
 * baba「チャットワークの送り先のところにメンションする人を選べるようにしてほしい。
 *       アサイン表自動取り込みもアサイン担当にメンションがほしくて、
 *       できれば任意で選んだ人にできるようにしたい」
 */
class ChatworkMentionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->admin()->create(['office' => '東京']);
    }

    /** チャットワークIDつきの社員（PersonFactory の既定が社員）。 */
    private function emp(string $name, ?string $cwid)
    {
        return PersonFactory::new()->create([
            'name' => $name,
            'office' => '東京',
            'chatwork_id' => $cwid,
        ]);
    }

    /** ① 選んだ人の宛名が作られる（選んだ順）。 */
    public function test_head_is_built_from_the_chosen_people(): void
    {
        $a = $this->emp('山田 太郎', '1111111');
        $b = $this->emp('鈴木 花子', '2222222');

        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, [$b->id, $a->id]);

        $this->assertSame(
            "[To:2222222]鈴木 花子さん[To:1111111]山田 太郎さん\n",
            ChatworkMentions::head(ChatworkRooms::SHEET_SYNC)
        );
    }

    /**
     * ② 誰も選んでいなければ空＝これまでとまったく同じ見た目になる。
     * ⚠ 空行を足さないこと（本文の頭が1行空くと見た目が変わる）。
     */
    public function test_no_one_chosen_means_no_change(): void
    {
        $this->assertSame('', ChatworkMentions::head(ChatworkRooms::SHEET_SYNC));
        $this->assertSame('本文', ChatworkMentions::prefix(ChatworkRooms::SHEET_SYNC, '本文'));
    }

    /**
     * ③ チャットワークIDが名簿に無い人は飛ばす。送信そのものは止めない。
     * ⚠ 1人ぶんのメンションが付かないために、知らせが丸ごと届かないのが一番困る。
     */
    public function test_a_person_without_a_chatwork_id_is_skipped_not_fatal(): void
    {
        $ok = $this->emp('山田 太郎', '1111111');
        $ng = $this->emp('佐藤 次郎', null);

        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, [$ok->id, $ng->id]);

        $this->assertSame("[To:1111111]山田 太郎さん\n", ChatworkMentions::head(ChatworkRooms::SHEET_SYNC));
        // 気づけるように、設定画面へ出す名前は拾えている。
        $this->assertSame(['佐藤 次郎'], ChatworkMentions::missingIdNames(ChatworkRooms::SHEET_SYNC));
    }

    /** ④ 知らせの種類ごとに別々に持てる。 */
    public function test_each_kind_is_separate(): void
    {
        $a = $this->emp('山田 太郎', '1111111');
        $b = $this->emp('鈴木 花子', '2222222');

        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, [$a->id]);
        ChatworkMentions::save(ChatworkRooms::FINANCE, [$b->id]);

        $this->assertSame("[To:1111111]山田 太郎さん\n", ChatworkMentions::head(ChatworkRooms::SHEET_SYNC));
        $this->assertSame("[To:2222222]鈴木 花子さん\n", ChatworkMentions::head(ChatworkRooms::FINANCE));
        $this->assertSame('', ChatworkMentions::head(ChatworkRooms::COUNT_DEADLINE));
    }

    /** ⑤ 名簿から消えた人のIDは残さない。 */
    public function test_people_who_left_are_dropped(): void
    {
        $a = $this->emp('山田 太郎', '1111111');
        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, [$a->id, 'P-NOT-EXIST']);

        $this->assertSame([$a->id], ChatworkMentions::ids(ChatworkRooms::SHEET_SYNC));
    }

    /** ⑥ 人数の上限を超えたぶんは切る（宛名だらけで本文が読めなくならないように）。 */
    public function test_too_many_people_are_cut(): void
    {
        $ids = [];
        for ($i = 0; $i < ChatworkMentions::MAX_PEOPLE + 3; $i++) {
            $ids[] = $this->emp('社員'.$i, (string) (1000000 + $i))->id;
        }

        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, $ids);

        $this->assertCount(ChatworkMentions::MAX_PEOPLE, ChatworkMentions::ids(ChatworkRooms::SHEET_SYNC));
    }

    /** ⑦ 設定画面から保存できる（部屋とメンションを1回で）。 */
    public function test_saving_from_the_settings_screen(): void
    {
        $a = $this->emp('山田 太郎', '1111111');

        $this->actingAsPerson($this->admin())
            ->post('/settings/chatwork-rooms', [
                'rooms' => [ChatworkRooms::SHEET_SYNC => '320609834'],
                'mentions' => [ChatworkRooms::SHEET_SYNC => [$a->id]],
            ])
            ->assertRedirect();

        $this->assertSame('320609834', ChatworkRooms::stored(ChatworkRooms::SHEET_SYNC));
        $this->assertSame([$a->id], ChatworkMentions::ids(ChatworkRooms::SHEET_SYNC));
    }

    /**
     * ⑧ チェックを全部外したら消える。
     * ⚠ チェックボックスは「外すと何も送られてこない」ので、保存し直さないと消えずに残る。
     *   これを忘れると「外したのにメンションされ続ける」になる。
     */
    public function test_unchecking_everyone_clears_it(): void
    {
        $a = $this->emp('山田 太郎', '1111111');
        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, [$a->id]);

        $this->actingAsPerson($this->admin())
            ->post('/settings/chatwork-rooms', ['rooms' => []])
            ->assertRedirect();

        $this->assertSame([], ChatworkMentions::ids(ChatworkRooms::SHEET_SYNC));
    }

    /**
     * ⑨ 毎朝の「届きました」は、既定ではメンションしない。
     * ⚠ 毎日鳴らすと見なくなって、本命の「届いていません」に気づけなくなる。
     */
    public function test_the_daily_report_does_not_mention_by_default(): void
    {
        $this->assertFalse(ChatworkMentions::mentionDailyReport());

        $this->actingAsPerson($this->admin())
            ->post('/settings/chatwork-rooms', ['rooms' => [], 'mention_daily_report' => '1'])
            ->assertRedirect();

        $this->assertTrue(ChatworkMentions::mentionDailyReport());
    }

    /**
     * ⑩ 設定画面：名前を入力して足す形（2026-11-16 小沼さん「全員が並んでいて使いにくい」）。
     * ・名前の候補に出るのは、チャットワークIDが名簿にある人だけ。
     * ・選んである人は札で出て、札の中の hidden が保存される（送る中身は今までと同じ mentions[知らせ][]）。
     */
    public function test_the_settings_screen_adds_people_by_name(): void
    {
        $yamada = $this->emp('山田 太郎', '1111111');
        $this->emp('佐藤 次郎', null);
        ChatworkMentions::save(ChatworkRooms::SHEET_SYNC, [$yamada->id]);

        $html = $this->actingAsPerson($this->admin())
            ->get('/settings')
            ->assertOk()
            ->assertSee('メンションする人')
            ->getContent();

        $this->assertStringContainsString('<option value="山田 太郎"></option>', $html, '候補に出る');
        $this->assertStringNotContainsString('<option value="佐藤 次郎"></option>', $html, 'IDが無い人は候補に出さない');
        $this->assertStringContainsString(
            '<input type="hidden" name="mentions[' . ChatworkRooms::SHEET_SYNC . '][]" value="' . $yamada->id . '">山田 太郎',
            $html,
            '選んである人は札で出る'
        );
        $this->assertStringContainsString('placeholder="名前を入力して追加"', $html);
        $this->assertStringNotContainsString('type="checkbox" name="mentions[', $html, '全員を並べるチェック欄は無い');
    }

    /** ⑪ 共通設定は見出しで畳める／「まとめて削除」のスイッチは Administrator のページへ移した（2026-11-16 小沼さん）。 */
    public function test_settings_folds_and_bulk_delete_switch_lives_in_admin_console(): void
    {
        $admin = $this->admin();

        $settings = $this->actingAsPerson($admin)->get('/settings')->assertOk()->getContent();
        $this->assertStringContainsString('setFoldAll(false)', $settings, 'すべて開く');
        $this->assertStringContainsString("localStorage.getItem(KEY", $settings, '開いたかたまりを覚える');
        $this->assertStringNotContainsString('action="/settings/board-bulk-delete"', $settings, '共通設定には無い');

        $console = $this->actingAsPerson($admin)->get('/admin-console')->assertOk()->getContent();
        $this->assertStringContainsString('action="/settings/board-bulk-delete"', $console, 'Administratorのページにある');
    }
}
