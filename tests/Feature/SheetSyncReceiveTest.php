<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use App\Models\SheetSync;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサイン表の自動受け取り（POST /sheet-sync）と受信箱の画面（2026-09-10 baba要望）。
 *
 * 【この入口の危なさ】
 * 人ではなく機械（スプレッドシート側の仕掛け）が叩くので、**ログインの門の外側**にある。
 * ⚠ 9/7に見つかった権限の穴（門の付け忘れ・public に置いたファイル）と同じ失敗を
 *   繰り返さないよう、次の3つをここで見張る。
 *   ①合言葉を決めていなければ入口そのものが閉じている
 *   ②合言葉が違えば何も受け取らない
 *   ③受け取ってもECSのデータ（案件・アサイン）は1件も変わらない
 */
class SheetSyncReceiveTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef';

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function employee(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-002', 'name' => '一般社員', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 実物と同じ見出しの「1案件＝1行」の中身（送られてくる rows と同じ形）。 */
    private function rows(array $override = []): array
    {
        $header = ['No.', '募集', '種別', '日程', '宿泊', 'コンテンツ', '案件規模', '営業担当',
            'オンラインツール', '配信種別', '顧客名(代理店名)', '運営場所', '複数開催',
            '集合', '解散', '拘束', '入場', '開始', '終了', '顧客担当名',
            '人数', 'チーム数', '運営人数', '形式', '運営方式', '担当', 'LINE作成', 'LINE概要送付',
            '引継', 'ダブチェ', '運営シート', 'シート期日', '台本', '台本期日', '音響', 'ロゴ',
            'カメラ', '記事', '動画', '会場場所', '集合形式', 'お酒', '物品担当', 'ケータリング',
            '移動方法', '会場種別', '備考', 'D', 'MC', 'OP', 'スタッフ'];

        $row = array_fill(0, 51, '');
        $row[0] = '1';
        $row[3] = '2026-10-07';
        $row[5] = '水合戦';
        $row[10] = '株式会社テスト';
        $row[13] = '08:00';
        $row[22] = '5';
        foreach ($override as $i => $v) {
            $row[$i] = $v;
        }

        return [$header, $row];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'token' => self::TOKEN,
            'book' => '2026年東京アサイン表9月～10月',
            'tab' => '202610',
            'office' => '東京',
            'rows' => $this->rows(),
        ], $override);
    }

    /**
     * ⚠ 合言葉を決めていなければ、入口そのものが閉じていること。
     *   ＝.env に書き忘れただけで「誰でも送れる入口」ができてしまう、を防ぐ。
     */
    public function test_the_door_is_closed_when_no_token_is_configured(): void
    {
        config(['ecs.sheet_sync_token' => null]);

        $this->postJson('/sheet-sync', $this->payload())->assertStatus(404);

        $this->assertSame(0, SheetSync::count(), '合言葉が無いのに受け取っています。');
    }

    /** ⚠ 合言葉が違えば何も受け取らないこと。 */
    public function test_a_wrong_token_is_refused(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', $this->payload(['token' => 'chigau']))->assertStatus(403);

        $this->assertSame(0, SheetSync::count(), '合言葉が違うのに受け取っています。');
    }

    /**
     * 正しく届いたら受信箱に入る。
     * ⚠ そのときECSのデータ（案件）は**1件も増えない**。反映は人が押したときだけ。
     */
    public function test_a_valid_send_lands_in_the_inbox_without_touching_ecs(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $res = $this->postJson('/sheet-sync', $this->payload())->assertOk()->json();

        $this->assertTrue($res['ok']);
        $this->assertSame('2026-10', $res['period']);
        $this->assertTrue($res['changed']);

        $sync = SheetSync::first();
        $this->assertNotNull($sync);
        $this->assertSame('東京', $sync->office);
        $this->assertSame('202610', $sync->tab);
        $this->assertNotNull($sync->received_at);
        $this->assertNotNull($sync->changed_at);
        $this->assertNull($sync->applied_at, 'まだ反映していないのに反映済みになっています。');

        $this->assertSame(0, Project::count(),
            '⚠ 届いただけで案件が入っています。受信箱は「置くだけ」でなければなりません。');
    }

    /** 同じ月が毎朝届いても行は増えない（月ごとに1行を上書きする）。 */
    public function test_the_same_month_does_not_pile_up(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', $this->payload())->assertOk();
        $second = $this->postJson('/sheet-sync', $this->payload())->assertOk()->json();

        $this->assertSame(1, SheetSync::count(), '同じ月が2行になっています。');
        $this->assertFalse($second['changed'], '同じ中身なのに「変わった」と言っています。');
    }

    /** 中身が変わったら「変わった」と分かること（人に見せるかどうかの目印）。 */
    public function test_a_changed_sheet_is_reported_as_changed(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', $this->payload())->assertOk();
        $res = $this->postJson('/sheet-sync', $this->payload([
            'rows' => $this->rows([22 => '9']),   // 運営人数を直した
        ]))->assertOk()->json();

        $this->assertTrue($res['changed']);
    }

    /** タブ名から何年何月ぶんかが読めなければ受け取らない（勘で決めない）。 */
    public function test_an_unreadable_tab_name_is_refused(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', $this->payload(['tab' => '10月シート']))
            ->assertStatus(422);

        $this->assertSame(0, SheetSync::count());
    }

    /** 知らない拠点名は受け取らない（勘で東京にしない）。 */
    public function test_an_unknown_office_is_refused(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', $this->payload(['office' => 'ハワイ']))
            ->assertStatus(422);
    }

    /** 受信箱の画面は管理者以上だけ（取込と同じ）。 */
    public function test_the_inbox_screen_is_for_managers(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', $this->payload())->assertOk();

        $this->actingAsPerson($this->manager())->get('/sheet-inbox')
            ->assertOk()
            ->assertSee('2026-10')
            ->assertSee('まだ一度も反映していません');

        // 一般社員は入れない（取込と同じ決まり）。
        $res = $this->actingAsPerson($this->employee())->get('/sheet-inbox');
        $this->assertNotSame(200, $res->getStatusCode(),
            '一般社員が受信箱を開けています（取込と同じ「管理者以上」でなければなりません）。');
    }

    /**
     * 受信箱から取込画面を開くと、届いた中身で差分が出る。
     * ⚠ ここが「毎朝の使い方」そのもの。読み取りはCSVと同じ道を通る。
     */
    public function test_the_import_screen_can_read_from_the_inbox(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', $this->payload())->assertOk();
        $sync = SheetSync::first();

        $me = $this->manager();

        // 画面が開いて「受信箱から読み込みました」と出る。
        $this->actingAsPerson($me)->get('/past-import?sync='.$sync->id)
            ->assertOk()
            ->assertSee('受信箱から読み込みました');

        // 下見＝まだECSに無いので「新しい案件」。
        $diff = $this->actingAsPerson($me)->post('/past-import/preview', ['sync' => $sync->id])
            ->assertOk()->json('rows.0.diff');
        $this->assertSame('new', $diff['kind']);

        // 取り込むと案件が入り、「いつ反映したか」が受信箱に残る。
        $this->actingAsPerson($me)->post('/past-import', ['sync' => $sync->id])
            ->assertRedirect('/past-import');

        $this->assertSame(1, Project::count());
        $this->assertNotNull($sync->fresh()->applied_at, '反映したのに記録が残っていません。');

        // 2回目の下見は「変化なし」＝どこまで反映したかが分かる。
        $diff2 = $this->actingAsPerson($me)->post('/past-import/preview', ['sync' => $sync->id])
            ->assertOk()->json('rows.0.diff');
        $this->assertSame('same', $diff2['kind']);
    }
}
