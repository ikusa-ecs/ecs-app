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

    /** ⑩ 設定画面に、選ぶ人の一覧と「ID未登録」の印が出ている。 */
    public function test_the_settings_screen_lists_people(): void
    {
        $this->emp('山田 太郎', '1111111');
        $this->emp('佐藤 次郎', null);

        $this->actingAsPerson($this->admin())
            ->get('/settings')
            ->assertOk()
            ->assertSee('メンションする人')
            ->assertSee('山田 太郎')
            ->assertSee('佐藤 次郎')
            ->assertSee('ID未登録');
    }
}
