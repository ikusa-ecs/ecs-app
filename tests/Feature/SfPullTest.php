<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SfInboxItem;
use App\Support\SfPull;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SFの商談を読んで受信箱に入れる部分（2026-11-20）。本物のSFにはつながず、にせものの返事で確かめる。
 */
class SfPullTest extends TestCase
{
    use RefreshDatabase;

    private const ID1 = '006AAAAAAAAAAAAAA1';

    private const ID2 = '006AAAAAAAAAAAAAA2';

    private function configure(): void
    {
        config([
            'services.salesforce.url' => 'https://example.my.salesforce.com',
            'services.salesforce.client_id' => 'test-id',
            'services.salesforce.client_secret' => 'test-secret',
        ]);
    }

    private function opp(string $id, string $stage = 'Aヨミ', array $more = []): array
    {
        return array_merge([
            'attributes' => ['type' => 'Opportunity'],
            'Id' => $id,
            'Name' => 'テスト商談',
            'StageName' => $stage,
            'Account' => ['attributes' => ['type' => 'Account'], 'Name' => 'テスト株式会社'],
            'Owner' => ['Email' => 'sales@example.com'],
            'kaisaibi__c' => '2027-02-10',
            'ninzu__c' => 50,
            'OpportunityLineItems' => null,
        ], $more);
    }

    /** @param  list<array<string, mixed>>  $records */
    private function fakeSf(array $records): void
    {
        // 2回目に呼んだとき、前のにせものの返事が残らないように入れ替える
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/services/oauth2/token' => Http::response(['access_token' => 'tok', 'instance_url' => 'https://example.my.salesforce.com']),
            '*/services/data/*' => Http::response(['done' => true, 'totalSize' => count($records), 'records' => $records]),
        ]);
    }

    public function test_鍵が無いあいだは何もしない(): void
    {
        Http::fake();
        $r = SfPull::run();
        $this->assertSame('off', $r['status']);
        Http::assertNothingSent();
    }

    public function test_読んだ商談は受信箱に入るだけで案件は増えない(): void
    {
        $this->configure();
        $this->fakeSf([$this->opp(self::ID1), $this->opp(self::ID2, '受注')]);
        $before = Project::count();

        $r = SfPull::run();

        $this->assertSame('ok', $r['status']);
        $this->assertSame(2, $r['created']);
        $this->assertSame($before, Project::count());
        $item = SfInboxItem::where('sf_opportunity_id', self::ID1)->firstOrFail();
        $this->assertSame(SfInboxItem::WAITING, $item->status);
        $this->assertSame('sales@example.com', $item->owner_email);
        $this->assertFalse($item->is_sample);
        // SFの管理用の印は持たない
        $this->assertArrayNotHasKey('attributes', $item->payload);
        $this->assertArrayNotHasKey('attributes', $item->payload['Account']);
    }

    public function test_失注などのフェーズは新しく入れない(): void
    {
        $this->configure();
        $this->fakeSf([$this->opp(self::ID1, '失注')]);

        $r = SfPull::run();

        $this->assertSame(0, $r['created']);
        $this->assertSame(0, SfInboxItem::count());
    }

    public function test_同じ商談は1行だけで_取り込み待ちなら中身を新しくする(): void
    {
        $this->configure();
        $this->fakeSf([$this->opp(self::ID1)]);
        SfPull::run();

        $this->fakeSf([$this->opp(self::ID1, 'Aヨミ', ['ninzu__c' => 80])]);
        $r = SfPull::run();

        $this->assertSame(1, SfInboxItem::count());
        $this->assertSame(1, $r['updated']);
        $this->assertSame(80, SfInboxItem::first()->payload['ninzu__c']);
    }

    public function test_登録ずみと見送りは中身を書き換えない(): void
    {
        $this->configure();
        $this->fakeSf([$this->opp(self::ID1), $this->opp(self::ID2)]);
        SfPull::run();
        SfInboxItem::where('sf_opportunity_id', self::ID1)->update(['status' => SfInboxItem::REGISTERED]);
        SfInboxItem::where('sf_opportunity_id', self::ID2)->update(['status' => SfInboxItem::SKIPPED]);

        $this->fakeSf([$this->opp(self::ID1, 'Aヨミ', ['ninzu__c' => 80]), $this->opp(self::ID2, 'Aヨミ', ['ninzu__c' => 80])]);
        $r = SfPull::run();

        $this->assertSame(0, $r['updated']);
        $this->assertSame(50, SfInboxItem::where('sf_opportunity_id', self::ID1)->first()->payload['ninzu__c']);
        $this->assertSame(SfInboxItem::SKIPPED, SfInboxItem::where('sf_opportunity_id', self::ID2)->first()->status);
    }

    public function test_検索文は2月分以降だけ_SFに無い屋内屋外は読まない_商品の番号も取る(): void
    {
        $soql = SfPull::soql(null);
        $this->assertStringContainsString('kaisaibi__c >= 2027-02-01', $soql);
        $this->assertStringNotContainsString('Indoor_Outdoor__c', $soql);
        $this->assertStringContainsString('Product2Id', $soql);
        $this->assertStringContainsString('Owner.Email', $soql);
        $this->assertStringNotContainsString('LastModifiedDate >=', $soql);
    }

    public function test_2回目からは前に読んだ時刻より後だけ_時刻はUTCで比べる(): void
    {
        // 日本時間 11/20 18:00 ＝ UTC 09:00。10分前から読み直す＝08:50Z
        $soql = SfPull::soql(Carbon::parse('2026-11-20 18:00:00', 'Asia/Tokyo')->subMinutes(10));
        $this->assertStringContainsString('LastModifiedDate >= 2026-11-20T08:50:00Z', $soql);

        $this->configure();
        $this->fakeSf([]);
        SfPull::run();
        $this->assertNotNull(Cache::get('sf_pull.last_ok'));
        SfPull::run();
        Http::assertSent(fn ($req) => str_contains($req->url(), '/query') && str_contains(urldecode($req->url()), 'LastModifiedDate >='));
    }

    public function test_SFに入れなかったら失敗と分かり_前に読んだ時刻は進めない(): void
    {
        $this->configure();
        Http::fake(['*/services/oauth2/token' => Http::response(['error' => 'invalid_client'], 400)]);

        $r = SfPull::run();

        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('invalid_client', $r['message']);
        $this->assertStringNotContainsString('test-secret', $r['message']);
        $this->assertNull(Cache::get('sf_pull.last_ok'));
    }

    public function test_続きのページがあれば続きも読む(): void
    {
        $this->configure();
        Http::fake([
            '*/services/oauth2/token' => Http::response(['access_token' => 'tok', 'instance_url' => 'https://example.my.salesforce.com']),
            '*/query/next-1' => Http::response(['done' => true, 'records' => [$this->opp(self::ID2)]]),
            '*/services/data/*/query?*' => Http::response(['done' => false, 'nextRecordsUrl' => '/services/data/v62.0/query/next-1', 'records' => [$this->opp(self::ID1)]]),
        ]);

        $r = SfPull::run();

        $this->assertSame(2, $r['created']);
    }
}
