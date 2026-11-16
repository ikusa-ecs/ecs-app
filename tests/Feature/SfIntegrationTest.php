<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\SfInboxItem;
use App\Models\SfProduct;
use App\Support\SfInboxSample;
use App\Support\SfOffice;
use App\Support\SfOpportunityMap;
use App\Support\SfProductCsv;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Salesforce連携の「SFにつながなくても作れる部分」（2026-11-16）。
 *  ・拠点の読み替え（SfOffice）
 *  ・商品の対応表の取込（取り込み直しても人の設定が消えない）
 *  ・受信箱（案件が増えるのは「登録」を押したときだけ・二重登録しない・管理者以上だけ）
 */
class SfIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->manager()->create(['office' => '東京', 'must_onboard' => false]);
    }

    // ── 拠点 ──

    public function test_拠点はオフィスを落として6拠点に合えば読み替える(): void
    {
        $this->assertSame('東京', SfOffice::toEcs('東京オフィス'));
        $this->assertSame('札幌', SfOffice::toEcs('札幌オフィス'));
        $this->assertSame('名古屋', SfOffice::toEcs(' 名古屋オフィス　'));
        $this->assertSame('大阪', SfOffice::toEcs('大阪'));
    }

    public function test_読み替えられない拠点は空にせず要確認_null(): void
    {
        $this->assertNull(SfOffice::toEcs('東北・北海道オフィス'));
        $this->assertNull(SfOffice::toEcs(''));
        $this->assertNull(SfOffice::toEcs(null));
        $this->assertNull(SfOffice::toEcs('横浜オフィス'));
    }

    // ── 商品の対応表 ──

    public function test_205件のCSVを取り込める_候補は写すだけで無視に決めない(): void
    {
        $r = SfProductCsv::import((string) file_get_contents(SfProductCsv::bundledPath()));

        $this->assertSame(205, $r['created']);
        $this->assertSame([], $r['skipped']);
        $this->assertSame(205, SfProduct::count());
        $this->assertSame(205, SfProduct::where('handling', SfProduct::PENDING)->count());
        $this->assertSame(28, SfProduct::whereNotNull('hint')->count());
        $this->assertSame('△機材の可能性', SfProduct::where('sf_product_id', '01tTL00000GeaGjYAJ')->value('hint'));
    }

    public function test_取り込み直しても人が決めた扱いとメモは消えない(): void
    {
        Content::create(['id' => 'CT-001', 'content_name' => 'チャンバラ合戦']);
        $csv = (string) file_get_contents(SfProductCsv::bundledPath());
        SfProductCsv::import($csv);

        SfProduct::where('sf_product_id', '01tTL00000GeaGTYAZ')->update(['handling' => 'content', 'content_id' => 'CT-001', 'note' => '確認ずみ']);
        SfProduct::where('sf_product_id', '01tTL00000GeaGjYAJ')->update(['handling' => 'ignore']);

        // 名前が変わったCSVを取り込み直す
        $csv2 = str_replace('チャンバラ合戦（合戦・運動）', 'チャンバラ合戦・新（合戦・運動）', $csv);
        $r = SfProductCsv::import($csv2);

        $this->assertSame(0, $r['created']);
        $this->assertSame(205, $r['updated']);
        $row = SfProduct::where('sf_product_id', '01tTL00000GeaGTYAZ')->first();
        $this->assertSame('チャンバラ合戦・新（合戦・運動）', $row->name);
        $this->assertSame('content', $row->handling);
        $this->assertSame('CT-001', $row->content_id);
        $this->assertSame('確認ずみ', $row->note);
        $this->assertSame('ignore', SfProduct::where('sf_product_id', '01tTL00000GeaGjYAJ')->value('handling'));
    }

    public function test_対応表の画面で1件ずつ保存できる_コンテンツは台帳にあるものだけ(): void
    {
        Content::create(['id' => 'CT-001', 'content_name' => 'チャンバラ合戦']);
        SfProductCsv::import((string) file_get_contents(SfProductCsv::bundledPath()));
        $me = $this->manager();

        $this->actingAsPerson($me)->get('/sf-products')->assertOk()->assertSee('まだ決めていない商品')->assertSee('205');

        $this->actingAsPerson($me)->postJson('/sf-products/save', ['sf_product_id' => '01tTL00000GeaGTYAZ', 'handling' => 'content', 'content_id' => 'CT-999'])
            ->assertStatus(422);
        $this->actingAsPerson($me)->postJson('/sf-products/save', ['sf_product_id' => '01tTL00000GeaGTYAZ', 'handling' => 'content', 'content_id' => 'CT-001'])
            ->assertOk()->assertJson(['ok' => true, 'pending' => 204]);
        $this->assertSame('CT-001', SfProduct::where('sf_product_id', '01tTL00000GeaGTYAZ')->value('content_id'));

        // 未対応に戻すと、コンテンツのつながりも外れる
        $this->actingAsPerson($me)->postJson('/sf-products/save', ['sf_product_id' => '01tTL00000GeaGTYAZ', 'handling' => 'pending'])
            ->assertOk()->assertJson(['pending' => 205]);
        $this->assertNull(SfProduct::where('sf_product_id', '01tTL00000GeaGTYAZ')->value('content_id'));
    }

    // ── 受信箱 ──

    public function test_受信箱に入っても見ても案件は増えない(): void
    {
        SfInboxSample::seed();
        $this->actingAsPerson($this->manager())->get('/sf-inbox')
            ->assertOk()->assertSee('案件として登録')->assertSee('見本')->assertSee('要確認')
            ->assertSee('複数日です');

        $this->assertSame(4, SfInboxItem::count());
        $this->assertSame(0, Project::count());
    }

    public function test_下見は対応表で引く_未対応は名前で出し無視は出さない(): void
    {
        Content::create(['id' => 'CT-001', 'content_name' => 'チャンバラ合戦']);
        SfProductCsv::import((string) file_get_contents(SfProductCsv::bundledPath()));
        SfProduct::where('sf_product_id', '01tTL00000GeaGTYAZ')->update(['handling' => 'content', 'content_id' => 'CT-001']);
        SfProduct::where('sf_product_id', '01tTL00000GeaGjYAJ')->update(['handling' => 'ignore']);

        $payload = SfInboxSample::payloads()[0];
        $p = SfOpportunityMap::preview($payload);
        $this->assertSame(['チャンバラ合戦'], array_column($p['contents'], 'content_name'));
        $this->assertSame([], $p['unmatched']);   // エアー遊具は無視＝出さない

        // エアー遊具を未対応に戻すと、名前のまま「未対応」に出る
        SfProduct::where('sf_product_id', '01tTL00000GeaGjYAJ')->update(['handling' => 'pending']);
        $p = SfOpportunityMap::preview($payload);
        $this->assertSame(['エアー遊具（合戦・運動）'], array_column($p['unmatched'], 'name'));

        // 商品Idが来ないとき（調査のときのSOQLの形）は、括弧つきフルネームで引く
        unset($payload['OpportunityLineItems']['records'][0]['Product2Id'], $payload['OpportunityLineItems']['records'][0]['PricebookEntry']['Product2']['Id']);
        $p = SfOpportunityMap::preview($payload);
        $this->assertSame(['チャンバラ合戦'], array_column($p['contents'], 'content_name'));
    }

    public function test_登録を押したときだけ案件が1件でき_二重には登録できない(): void
    {
        Content::create(['id' => 'CT-001', 'content_name' => 'チャンバラ合戦']);
        SfProductCsv::import((string) file_get_contents(SfProductCsv::bundledPath()));
        SfProduct::where('sf_product_id', '01tTL00000GeaGTYAZ')->update(['handling' => 'content', 'content_id' => 'CT-001']);
        SfInboxSample::seed();
        $me = $this->manager();
        $item = SfInboxItem::where('sf_opportunity_id', '006SAMPLE000000001')->first();

        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/register", ['office' => '東京'])->assertRedirect('/sf-inbox');
        $this->assertSame(1, Project::count());

        $project = Project::first();
        $this->assertSame('P-2027-0001', $project->id);
        $this->assertSame('東京', $project->office);
        $this->assertSame('2027-01-20', $project->start_date->format('Y-m-d'));
        $this->assertSame('【見本】株式会社サンプル商事', $project->client);
        $this->assertSame(['CT-001'], $project->content_ids);
        // 未対応のエアー遊具は、黙って落とさず案件名に残る（台帳には足さない）
        $this->assertSame(['チャンバラ合戦', 'エアー遊具（合戦・運動）'], $project->content_names);
        $this->assertSame(80, $project->guest_count);
        $this->assertSame(6, $project->required_count);
        $this->assertSame('Aヨミ', $project->yomi);
        $this->assertFalse($project->staff_published);
        $this->assertSame(1, Content::count());

        $item->refresh();
        $this->assertSame(SfInboxItem::REGISTERED, $item->status);
        $this->assertSame($project->id, $item->project_id);

        // もう一度押しても増えない・戻しても増えない
        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/register", ['office' => '東京'])->assertSessionHas('error');
        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/reopen")->assertSessionHas('error');
        $this->assertSame(1, Project::count());
        $this->assertSame(SfInboxItem::REGISTERED, $item->refresh()->status);
    }

    public function test_拠点が要確認なら選ぶまで登録しない(): void
    {
        SfInboxSample::seed();
        $me = $this->manager();
        $item = SfInboxItem::where('sf_opportunity_id', '006SAMPLE000000003')->first(); // 東北・北海道オフィス

        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/register", ['office' => ''])->assertSessionHas('error');
        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/register", ['office' => '横浜'])->assertSessionHas('error');
        $this->assertSame(0, Project::count());

        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/register", ['office' => '札幌'])->assertSessionHas('status');
        $this->assertSame('札幌', Project::first()->office);
        $this->assertSame(45, Project::first()->guest_count);
        $this->assertTrue((bool) Project::first()->count_tentative);   // 運営人数が空＝仮
    }

    public function test_見送りは消さずに残し_取り込み待ちに戻せる(): void
    {
        SfInboxSample::seed();
        $me = $this->manager();
        $item = SfInboxItem::where('sf_opportunity_id', '006SAMPLE000000002')->first();

        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/skip")->assertSessionHas('status');
        $this->assertSame(SfInboxItem::SKIPPED, $item->refresh()->status);
        // 見送りは登録できない
        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/register", ['office' => '大阪'])->assertSessionHas('error');
        $this->assertSame(0, Project::count());

        $this->actingAsPerson($me)->post("/sf-inbox/{$item->id}/reopen")->assertSessionHas('status');
        $this->assertSame(SfInboxItem::WAITING, $item->refresh()->status);
        $this->assertSame(4, SfInboxItem::count());
    }

    public function test_見本だけ消せる(): void
    {
        SfInboxSample::seed();
        SfInboxItem::create(['sf_opportunity_id' => '006TL00002MxQIeYAN', 'payload' => ['Id' => '006TL00002MxQIeYAN'], 'status' => 'waiting']);

        $this->artisan('ecs:sf-sample --clear')->assertSuccessful();
        $this->assertSame(1, SfInboxItem::count());
        $this->assertSame('006TL00002MxQIeYAN', SfInboxItem::first()->sf_opportunity_id);
    }

    public function test_社員とスタッフは入れない_登録もできない(): void
    {
        SfInboxSample::seed();
        $item = SfInboxItem::first();
        $employee = PersonFactory::new()->create(['must_onboard' => false]);
        $staff = PersonFactory::new()->staff()->create(['must_onboard' => false]);

        $this->actingAsPerson($employee)->get('/sf-inbox')->assertForbidden();
        $this->actingAsPerson($employee)->get('/sf-products')->assertForbidden();
        $this->actingAsPerson($employee)->post("/sf-inbox/{$item->id}/register", ['office' => '東京'])->assertForbidden();
        $this->actingAsPerson($employee)->post('/sf-products/import', ['bundled' => 1])->assertForbidden();
        $this->actingAsPerson($staff)->get('/sf-inbox')->assertRedirect('/staff-portal');
        $this->assertSame(0, Project::count());
        $this->assertSame(0, SfProduct::count());

        // メニューの入口も管理者以上だけ
        $this->actingAsPerson($employee)->get('/settings')->assertOk()->assertDontSee('Salesforce受信箱');
        $this->actingAsPerson($this->manager())->get('/settings')->assertOk()->assertSee('Salesforce受信箱')->assertSee('Salesforce商品の対応表');
    }
}
