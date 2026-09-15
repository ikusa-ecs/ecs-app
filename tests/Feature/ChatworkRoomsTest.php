<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Support\ChatworkRooms;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * チャットワークの「どの知らせを、どの部屋へ送るか」（2026-09-15 baba要望）。
 *
 * 【babaの言葉】「チャットワークの部屋IDはそれぞれ贈る場所が違うから設定できるようにしてほしい」
 *
 * ⚠ **部屋IDは鍵ではない**ので、.env ではなく設定画面から変えられるようにした。
 *   （トークンは鍵なので .env のまま＝エンジニア依頼）
 * ⚠ 何も設定しなければ、今までとまったく同じ動き（共通の部屋へ送る）。
 */
class ChatworkRoomsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    public function test_何も決めていなければ共通の部屋へ送る(): void
    {
        config(['services.chatwork.room' => '111']);

        foreach ([ChatworkRooms::COUNT_DEADLINE, ChatworkRooms::FINANCE, ChatworkRooms::SHEET_SYNC] as $kind) {
            $this->assertSame('111', ChatworkRooms::for($kind), "{$kind} が共通の部屋に落ちていない");
        }
    }

    public function test_知らせごとに送り先を分けられる(): void
    {
        config(['services.chatwork.room' => '111']);

        ChatworkRooms::save([
            ChatworkRooms::SHEET_SYNC => '222',
            ChatworkRooms::FINANCE => '333',
        ]);

        $this->assertSame('222', ChatworkRooms::for(ChatworkRooms::SHEET_SYNC));
        $this->assertSame('333', ChatworkRooms::for(ChatworkRooms::FINANCE));
        // 決めていないものは共通のまま。
        $this->assertSame('111', ChatworkRooms::for(ChatworkRooms::COUNT_DEADLINE));
    }

    public function test_共通を決めれば全部そこへ行く(): void
    {
        config(['services.chatwork.room' => '111']);

        ChatworkRooms::save([ChatworkRooms::COMMON => '999']);

        $this->assertSame('999', ChatworkRooms::for(ChatworkRooms::COUNT_DEADLINE));
        $this->assertSame('999', ChatworkRooms::for(ChatworkRooms::SHEET_SYNC));
    }

    /** ⚠ テスト用の部屋だけは共通に落とさない（本番の部屋へ誤爆させないため）。 */
    public function test_テスト用は共通に落とさない(): void
    {
        config(['services.chatwork.room' => '111', 'services.chatwork.test_room' => '222']);

        ChatworkRooms::save([ChatworkRooms::COMMON => '999']);

        $this->assertSame('222', ChatworkRooms::for(ChatworkRooms::TEST));
    }

    /** チャットワークのURLを丸ごと貼っても番号だけ取り出す（取り違えを防ぐ）。 */
    public function test_URLを丸ごと貼っても番号を取り出す(): void
    {
        $this->assertSame('320609834', ChatworkRooms::clean('https://www.chatwork.com/#!rid320609834'));
        $this->assertSame('320609834', ChatworkRooms::clean('  320609834 '));
        $this->assertSame('', ChatworkRooms::clean('でたらめ'));
        $this->assertSame('', ChatworkRooms::clean(''));
    }

    /** ⚠ 読み取れない文字は保存せず、理由を知らせる（勘で入れない）。 */
    public function test_読み取れない文字は受け付けず知らせる(): void
    {
        $rejected = ChatworkRooms::save([ChatworkRooms::SHEET_SYNC => 'でたらめ']);

        $this->assertNotEmpty($rejected);
        $this->assertSame('', ChatworkRooms::stored(ChatworkRooms::SHEET_SYNC));
    }

    /** 空にすると「決めない」＝共通の部屋に戻る。 */
    public function test_空にすると共通に戻る(): void
    {
        config(['services.chatwork.room' => '111']);

        ChatworkRooms::save([ChatworkRooms::SHEET_SYNC => '222']);
        $this->assertSame('222', ChatworkRooms::for(ChatworkRooms::SHEET_SYNC));

        ChatworkRooms::save([ChatworkRooms::SHEET_SYNC => '']);
        $this->assertSame('111', ChatworkRooms::for(ChatworkRooms::SHEET_SYNC));
    }

    public function test_設定画面から保存できる(): void
    {
        $me = $this->admin();

        $this->actingAsPerson($me)->get('/settings')
            ->assertOk()
            ->assertSee('チャットワークの送り先');

        $this->actingAsPerson($me)
            ->post('/settings/chatwork-rooms', ['rooms' => [ChatworkRooms::SHEET_SYNC => '555']])
            ->assertRedirect();

        $this->assertSame('555', ChatworkRooms::stored(ChatworkRooms::SHEET_SYNC));
    }
}
