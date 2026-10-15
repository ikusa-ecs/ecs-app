<?php

namespace Tests\Unit;

use App\Models\ContentPaperStock;
use App\Support\PaperStockService;
use Database\Factories\ContentFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 謎解きの紙 在庫集計（PaperStockService）の単体テスト。テスト仕様書 UT-PPR-01。
 *
 * 集計ルール：
 *   ・必要枚数 = ceil(チーム数) × sheets_per_team
 *   ・開催日が今日以降＝「必要数(今後)」、過去＝「消費数(開催済み)」
 *   ・在庫 = 入庫 − 消費 / 過不足 = 在庫 − 必要数(今後)
 *   ・下書き／オンライン開催は集計対象外
 * DB が必要（RefreshDatabase）。
 */
class PaperStockServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 在庫＝入庫−消費、必要数(今後)、不足数の一連の計算が正しい。 */
    public function test_stock_equals_received_minus_consumed(): void
    {
        // 紙が必要なコンテンツ：1チーム2枚。
        $c = ContentFactory::new()->create([
            'id' => 'CT-P01',
            'content_name' => '謎解きA',
            'needs_paper' => true,
            'sheets_per_team' => 2,
        ]);

        // 開催済み（過去）：チーム5 → 5×2 = 10枚 消費。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P01'],
            'start_date' => now()->subDays(10)->format('Y-m-d'),
            'team_count' => 5,
            'status' => '完了',
            'format' => '対面',
        ]);

        // 今後（未来）：チーム3 → 3×2 = 6枚 必要。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P01'],
            'start_date' => now()->addDays(10)->format('Y-m-d'),
            'team_count' => 3,
            'status' => '確定',
            'format' => '対面',
        ]);

        // 入庫 15枚（手入力）。入れはじめたのは30日前＝10日前の案件の消費は数える。
        $s = ContentPaperStock::create(['content_id' => 'CT-P01', 'office' => '東京', 'received_count' => 15]);
        $s->forceFill(['created_at' => now()->subDays(30)])->save();

        // 入れはじめる前（60日前）の案件は消費に数えない（2026-10-09 過去の取込で在庫が減って見えないように）。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P01'],
            'start_date' => now()->subDays(60)->format('Y-m-d'),
            'team_count' => 50,
            'status' => '確定',
            'format' => '対面',
        ]);

        $result = (new PaperStockService())->compute('東京');
        $row = collect($result['stock'])->firstWhere('id', 'CT-P01');

        $this->assertSame(6, $row['future'], '必要数(今後)');
        $this->assertSame(10, $row['past'], '消費数(開催済み)');
        $this->assertSame(15, $row['received'], '入庫数');
        $this->assertSame(5, $row['zaiko'], '在庫＝入庫15−消費10');
        // 過不足＝在庫5−必要6 = −1 → 不足1。
        $this->assertSame(1, $row['short'], '不足数');
    }

    /** チーム数が空ならお客様人数÷6(切り上げ)で推定される。 */
    public function test_teams_estimated_from_guest_count(): void
    {
        ContentFactory::new()->create([
            'id' => 'CT-P02',
            'needs_paper' => true,
            'sheets_per_team' => 1,
        ]);

        // team_count 未入力・guest_count 13 → ceil(13/6)=3チーム → 3×1 = 3枚（今後）。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P02'],
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'team_count' => null,
            'guest_count' => 13,
            'status' => '確定',
            'format' => '対面',
        ]);

        $result = (new PaperStockService())->compute();
        $row = collect($result['stock'])->firstWhere('id', 'CT-P02');

        $this->assertSame(3, $row['future'], 'guest_count 13 → ceil(13/6)=3チーム×1枚');
    }

    /** 下書き案件とオンライン開催は集計対象外。 */
    public function test_draft_and_online_projects_are_excluded(): void
    {
        ContentFactory::new()->create([
            'id' => 'CT-P03',
            'needs_paper' => true,
            'sheets_per_team' => 1,
        ]);

        // 下書き（除外）
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P03'],
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'team_count' => 4,
            'status' => '下書き',
            'format' => '対面',
        ]);

        // オンライン（紙不要・除外）
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P03'],
            'start_date' => now()->addDays(4)->format('Y-m-d'),
            'team_count' => 4,
            'status' => '確定',
            'format' => 'オンライン',
        ]);

        $result = (new PaperStockService())->compute();
        $row = collect($result['stock'])->firstWhere('id', 'CT-P03');

        // どちらも数えないので必要数は0。
        $this->assertSame(0, $row['future']);
        $this->assertSame(0, $row['past']);
    }

    /** needs_paper=false のコンテンツは在庫表に出ない（対象外）。 */
    public function test_non_paper_content_is_not_listed(): void
    {
        ContentFactory::new()->create([
            'id' => 'CT-NOPAPER',
            'needs_paper' => false,
        ]);

        $result = (new PaperStockService())->compute();

        $this->assertNull(
            collect($result['stock'])->firstWhere('id', 'CT-NOPAPER'),
            '紙不要コンテンツが在庫表に混ざっている'
        );
    }

    /**
     * 在庫は拠点ごと（2026-10-15）。巻き取りは巻き取った拠点の紙・ヘルプは登録した拠点の紙。
     */
    public function test_在庫は拠点ごと_巻き取りは巻き取った拠点から減らす(): void
    {
        ContentFactory::new()->create(['id' => 'CT-P04', 'needs_paper' => true, 'sheets_per_team' => 1]);
        $past = now()->subDays(3)->format('Y-m-d');

        // 東京の案件（10枚）・名古屋が巻き取った東京の案件（7枚）・名古屋がヘルプに入った東京の案件（5枚）
        ProjectFactory::new()->create(['content_ids' => ['CT-P04'], 'start_date' => $past, 'team_count' => 10, 'status' => '確定', 'format' => '対面']);
        $taken = ProjectFactory::new()->create(['content_ids' => ['CT-P04'], 'start_date' => $past, 'team_count' => 7, 'status' => '確定', 'format' => '対面', 'office' => '東京']);
        \App\Models\ProjectShare::create(['project_id' => $taken->id, 'office' => '名古屋', 'kind' => '巻き取り']);
        $help = ProjectFactory::new()->create(['content_ids' => ['CT-P04'], 'start_date' => $past, 'team_count' => 5, 'status' => '確定', 'format' => '対面', 'office' => '東京']);
        \App\Models\ProjectShare::create(['project_id' => $help->id, 'office' => '名古屋', 'kind' => 'ヘルプ']);

        foreach (['東京' => 200, '名古屋' => 100] as $o => $n) {
            $s = ContentPaperStock::create(['content_id' => 'CT-P04', 'office' => $o, 'received_count' => $n]);
            $s->forceFill(['created_at' => now()->subDays(30)])->save();
        }

        $r = (new PaperStockService())->compute();
        $this->assertSame(185, $r['byOffice']['東京']['CT-P04']['zaiko'], '東京＝200−10−5（ヘルプは東京の紙）');
        $this->assertSame(93, $r['byOffice']['名古屋']['CT-P04']['zaiko'], '名古屋＝100−7（巻き取りは名古屋の紙）');
        $this->assertFalse($r['byOffice']['東京']['CT-P04']['low']);
        $this->assertTrue($r['byOffice']['名古屋']['CT-P04']['low'], '120部切れ');

        // 名古屋を選ぶと、名古屋の紙の案件だけが明細に出る。
        $nagoya = (new PaperStockService())->compute('名古屋');
        $this->assertSame([$taken->id], array_column($nagoya['detail'], 'projectId'));
    }

    /** 120部を切ったら1回だけ知らせる・入庫して戻ったら、また切ったときに知らせる。 */
    public function test_120部を切ったら1回だけ知らせる(): void
    {
        config(['services.chatwork.token' => 'cw-test-token', 'services.chatwork.room' => '999']);
        \Illuminate\Support\Facades\Http::fake(['api.chatwork.com/*' => \Illuminate\Support\Facades\Http::response(['message_id' => '1'], 200)]);

        ContentFactory::new()->create(['id' => 'CT-P05', 'content_name' => '謎解きB', 'needs_paper' => true, 'sheets_per_team' => 1]);
        ProjectFactory::new()->create(['content_ids' => ['CT-P05'], 'start_date' => now()->subDays(2)->format('Y-m-d'), 'team_count' => 30, 'status' => '確定', 'format' => '対面']);
        $s = ContentPaperStock::create(['content_id' => 'CT-P05', 'office' => '東京', 'received_count' => 140]);
        $s->forceFill(['created_at' => now()->subDays(30)])->save();
        // 入庫数を入れていない拠点は知らせない（在庫を管理していない）
        ContentFactory::new()->create(['id' => 'CT-P06', 'needs_paper' => true]);

        $first = \App\Support\PaperStockAlert::run();   // 140−30＝110部
        $this->assertTrue($first['sent']);
        $this->assertCount(1, $first['low']);
        $this->assertStringContainsString('東京／謎解きB：残り 110部', $first['low'][0]);

        $this->assertSame([], \App\Support\PaperStockAlert::run()['low'], '2回目は送らない');

        $s->update(['received_count' => 300]);   // 入庫して戻った
        \App\Support\PaperStockAlert::run();
        $this->assertNull($s->fresh()->alerted_at, '戻ったら印を外す');

        $s->update(['received_count' => 100]);   // また切った
        $this->assertCount(1, \App\Support\PaperStockAlert::run()['low'], 'また切ったら送る');
        \Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    /** 追加発注：記録しても在庫は増えない（発注中）→「届いた」で入庫数に足す・二度押しても1回だけ。 */
    public function test_追加発注は届いたら入庫数に足す(): void
    {
        $me = \Database\Factories\PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        ContentFactory::new()->create(['id' => 'CT-P08', 'content_name' => '謎解きC', 'needs_paper' => true]);
        ContentPaperStock::create(['content_id' => 'CT-P08', 'office' => '名古屋', 'received_count' => 100]);

        $this->actingAsPerson($me)->post('/paper-stock/orders', [
            'office' => '名古屋', 'content_id' => 'CT-P08', 'count' => 200, 'expected_on' => now()->addDays(5)->toDateString(), 'note' => '第2刷',
        ])->assertRedirect('/paper-stock?office='.urlencode('名古屋'));

        $o = \App\Models\PaperOrder::firstOrFail();
        $this->assertSame('管理者', $o->ordered_by);
        $row = (new PaperStockService())->compute('名古屋')['byOffice']['名古屋']['CT-P08'];
        $this->assertSame(100, $row['received'], '発注しただけでは在庫は増えない');
        $this->assertSame(200, $row['ordered']);

        $this->actingAsPerson($me)->get('/paper-stock?office='.urlencode('名古屋'))
            ->assertOk()->assertSee('📦 発注中')->assertSee('第2刷')->assertSee('届いた');

        $this->actingAsPerson($me)->post("/paper-stock/orders/{$o->id}/arrive");
        $this->actingAsPerson($me)->post("/paper-stock/orders/{$o->id}/arrive");   // 二度押し
        $this->assertSame(300, ContentPaperStock::where('office', '名古屋')->value('received_count'), '100＋200（1回だけ）');
        $this->assertNotNull($o->fresh()->arrived_on);
        $this->assertSame(0, (new PaperStockService())->compute()['byOffice']['名古屋']['CT-P08']['ordered']);
        $this->actingAsPerson($me)->get('/paper-stock?office=all')
            ->assertOk()->assertSee('追加発注（全拠点）')->assertSee('届いた発注')->assertDontSee('>発注を記録</button>', false);

        // 届いたものは取り消せない／発注中なら取り消せる
        $this->actingAsPerson($me)->post("/paper-stock/orders/{$o->id}/cancel");
        $this->assertNotNull(\App\Models\PaperOrder::find($o->id));
        $o2 = \App\Models\PaperOrder::create(['content_id' => 'CT-P08', 'office' => '名古屋', 'count' => 5, 'ordered_on' => today()]);
        $this->actingAsPerson($me)->post("/paper-stock/orders/{$o2->id}/cancel");
        $this->assertNull(\App\Models\PaperOrder::find($o2->id));

        // 全拠点では見るだけ（記録はできない）
        $this->actingAsPerson($me)->post('/paper-stock/orders', ['office' => 'all', 'content_id' => 'CT-P08', 'count' => 1]);
        $this->assertSame(1, \App\Models\PaperOrder::count());
    }

    /** タブ（2026-10-15 baba「縦に長くて使いづらい」）＝押したものだけ出す。はじめは在庫・発注。 */
    public function test_タブで分けて出す(): void
    {
        $me = \Database\Factories\PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        ContentFactory::new()->create(['id' => 'CT-P10', 'content_name' => '謎解きE', 'needs_paper' => true]);
        ProjectFactory::new()->create(['content_ids' => ['CT-P10'], 'start_date' => now()->addDays(5)->format('Y-m-d'),
            'team_count' => 4, 'status' => '確定', 'format' => '対面', 'client' => '明細の会社']);

        $this->actingAsPerson($me)->get('/paper-stock')->assertOk()
            ->assertSee('東京の在庫')->assertSee('追加発注（東京）')
            ->assertDontSee('コンテンツ × 月')->assertDontSee('明細の会社');

        $this->actingAsPerson($me)->get('/paper-stock?tab=month')->assertOk()
            ->assertSee('コンテンツ × 月')->assertDontSee('東京の在庫')->assertDontSee('明細の会社');

        $this->actingAsPerson($me)->get('/paper-stock?tab=detail')->assertOk()
            ->assertSee('明細の会社')->assertDontSee('東京の在庫');

        $this->actingAsPerson($me)->get('/paper-stock?office=all&tab=month')->assertOk()
            ->assertSee('拠点 × 月')->assertDontSee('コンテンツ × 拠点');
    }

    /** 120部の知らせに「発注中」を添える（二重に発注しないように）。 */
    public function test_知らせに発注中を添える(): void
    {
        config(['services.chatwork.token' => 'cw-test-token', 'services.chatwork.room' => '999']);
        \Illuminate\Support\Facades\Http::fake(['api.chatwork.com/*' => \Illuminate\Support\Facades\Http::response(['message_id' => '1'], 200)]);
        ContentFactory::new()->create(['id' => 'CT-P09', 'content_name' => '謎解きD', 'needs_paper' => true]);
        ContentPaperStock::create(['content_id' => 'CT-P09', 'office' => '東京', 'received_count' => 50]);
        \App\Models\PaperOrder::create(['content_id' => 'CT-P09', 'office' => '東京', 'count' => 300, 'ordered_on' => today()]);

        $r = \App\Support\PaperStockAlert::run();
        $this->assertStringContainsString('📦発注中 300部あり', $r['low'][0]);
    }

    /** 送れなかった（鍵が無い）ときは印を付けない＝次の朝にもう一度送る。 */
    public function test_送れなかったら次の朝にもう一度(): void
    {
        config(['services.chatwork.token' => '']);
        ContentFactory::new()->create(['id' => 'CT-P07', 'needs_paper' => true]);
        ContentPaperStock::create(['content_id' => 'CT-P07', 'office' => '東京', 'received_count' => 50]);

        $r = \App\Support\PaperStockAlert::run();
        $this->assertFalse($r['sent']);
        $this->assertNull(ContentPaperStock::first()->alerted_at);
    }
}
