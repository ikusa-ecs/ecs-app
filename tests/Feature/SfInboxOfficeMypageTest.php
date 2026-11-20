<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use App\Models\SfInboxItem;
use App\Support\SfInboxRegister;
use App\Support\SfInboxSample;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SF受信箱まわりの3つ（2026-11-20 小沼さん）。
 *  A. 受信箱を拠点ごとに分ける（はじめは自分の拠点・拠点が分からない商談はどの拠点でも出る）
 *  B. 担当営業のマイページに自分あての商談を出し、本人だけが登録・見送りできる（他人の商談は社員には不可）
 *  C. SFから登録した案件の編集画面に「Salesforceの商談を開く」リンク（つなぎ先が空なら出さない）
 *
 * 見本（SfInboxSample）の顔ぶれ：1=東京／2=大阪／3=東北・北海道（拠点が分からない）／4=拠点空欄。担当営業はみな sample-sales@example.com。
 */
class SfInboxOfficeMypageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKYO = '【見本】株式会社サンプル商事';
    private const OSAKA = '【見本】サンプル工業株式会社';
    private const UNKNOWN = '【見本】サンプル大学';

    private function manager(string $office = '東京'): Person
    {
        return PersonFactory::new()->manager()->create(['office' => $office, 'must_onboard' => false, 'email' => 'boss@example.com']);
    }

    /** 見本の担当営業本人（大文字が混じっていても本人あつかいになることも見る）。 */
    private function owner(): Person
    {
        return PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false, 'email' => ' Sample-Sales@Example.com ']);
    }

    private function other(): Person
    {
        return PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false, 'email' => 'someone-else@example.com']);
    }

    private function item(int $n): SfInboxItem
    {
        return SfInboxItem::where('sf_opportunity_id', sprintf('006SAMPLE%09d', $n))->firstOrFail();
    }

    // ── A. 拠点ごと ──

    public function test_受信箱ははじめ自分の拠点_拠点が分からない商談はどの拠点でも出る(): void
    {
        SfInboxSample::seed();
        $me = $this->manager('東京');

        $this->actingAsPerson($me)->get('/sf-inbox')
            ->assertOk()
            ->assertViewHas('officeScope', '東京')
            ->assertSee('表示する拠点')
            ->assertSee(self::TOKYO)->assertSee(self::UNKNOWN)->assertDontSee(self::OSAKA)
            ->assertSee('拠点が分からない商談');

        $this->actingAsPerson($me)->get('/sf-inbox?office='.urlencode('大阪'))
            ->assertOk()
            ->assertSee(self::OSAKA)->assertSee(self::UNKNOWN)->assertDontSee(self::TOKYO);

        $this->actingAsPerson($me)->get('/sf-inbox?office=all')
            ->assertOk()
            ->assertViewHas('officeScope', null)
            ->assertSee(self::TOKYO)->assertSee(self::OSAKA)->assertSee(self::UNKNOWN);
    }

    public function test_タブの件数も選んだ拠点で数える(): void
    {
        SfInboxSample::seed();
        $me = $this->manager('東京');

        // 東京＝東京1件＋拠点が分からない2件（東北・北海道／空欄）
        $res = $this->actingAsPerson($me)->get('/sf-inbox');
        $this->assertSame(3, $res->viewData('counts')['waiting']);
        $res = $this->actingAsPerson($me)->get('/sf-inbox?office=all');
        $this->assertSame(4, $res->viewData('counts')['waiting']);
    }

    public function test_登録ずみは案件の拠点で分ける(): void
    {
        SfInboxSample::seed();
        $me = $this->manager('東京');
        // 拠点が分からない商談を、人が札幌と選んで登録
        $this->assertTrue(SfInboxRegister::register($this->item(3), '札幌', $me->id)['ok']);

        $this->actingAsPerson($me)->get('/sf-inbox?show=registered&office='.urlencode('東京'))
            ->assertOk()->assertDontSee(self::UNKNOWN);
        $this->actingAsPerson($me)->get('/sf-inbox?show=registered&office='.urlencode('札幌'))
            ->assertOk()->assertSee(self::UNKNOWN);
    }

    public function test_押したあとも見ていた拠点とタブに戻る(): void
    {
        SfInboxSample::seed();
        $me = $this->manager('東京');

        $this->actingAsPerson($me)
            ->post('/sf-inbox/'.$this->item(2)->id.'/skip', ['show' => 'waiting', 'view_office' => '大阪'])
            ->assertRedirect('/sf-inbox?'.http_build_query(['show' => 'waiting', 'office' => '大阪']));

        $this->actingAsPerson($me)
            ->post('/sf-inbox/'.$this->item(2)->id.'/reopen', ['show' => 'skipped', 'view_office' => 'all'])
            ->assertRedirect('/sf-inbox?show=skipped&office=all');
    }

    // ── B. マイページ ──

    public function test_担当営業のマイページに自分あての取り込み待ちが出る_見ただけでは登録しない(): void
    {
        SfInboxSample::seed();
        $this->item(2)->update(['status' => SfInboxItem::SKIPPED]);   // 見送りずみは「届いた商談」の件数に入れず、下の「見送った商談」に畳んで出す（11/20）

        $this->actingAsPerson($this->owner())->get('/mypage')
            ->assertOk()
            ->assertSee('Salesforceから届いた商談（3件）')
            ->assertSee(self::TOKYO)->assertSee('見送った商談（1件）')
            ->assertSee('案件として登録');

        $this->actingAsPerson($this->other())->get('/mypage')
            ->assertOk()->assertDontSee('Salesforceから届いた商談');

        $this->assertSame(0, Project::count());
    }

    public function test_メールの列が空でも中身の担当営業で本人に届く(): void
    {
        SfInboxSample::seed();
        SfInboxItem::query()->update(['owner_email' => null]);

        $this->actingAsPerson($this->owner())->get('/mypage')
            ->assertOk()->assertSee('Salesforceから届いた商談（4件）');
    }

    public function test_メールが空の人には誰の商談も出さない(): void
    {
        SfInboxSample::seed();
        SfInboxItem::query()->update(['owner_email' => null, 'payload' => ['Id' => 'x']]);
        $noMail = PersonFactory::new()->create(['must_onboard' => false, 'email' => null]);

        $this->actingAsPerson($noMail)->get('/mypage')
            ->assertOk()->assertDontSee('Salesforceから届いた商談');
    }

    public function test_本人はマイページから登録できる_二重には登録できない(): void
    {
        SfInboxSample::seed();
        $owner = $this->owner();
        $item = $this->item(1);

        $this->actingAsPerson($owner)->post("/mypage/sf-inbox/{$item->id}/register", ['office' => '東京'])
            ->assertRedirect('/mypage')->assertSessionHas('sf_status');
        $this->assertSame(1, Project::count());
        $item->refresh();
        $this->assertSame(SfInboxItem::REGISTERED, $item->status);
        $this->assertSame($owner->id, $item->decided_by);

        $this->actingAsPerson($owner)->post("/mypage/sf-inbox/{$item->id}/register", ['office' => '東京'])
            ->assertSessionHas('sf_error');
        $this->assertSame(1, Project::count());
    }

    public function test_本人でも拠点が分からない商談は拠点を選ぶまで登録しない(): void
    {
        SfInboxSample::seed();
        $item = $this->item(3);

        $this->actingAsPerson($this->owner())->post("/mypage/sf-inbox/{$item->id}/register", ['office' => ''])
            ->assertSessionHas('sf_error');
        $this->assertSame(0, Project::count());
    }

    public function test_本人はマイページから見送りできる(): void
    {
        SfInboxSample::seed();
        $item = $this->item(1);

        $this->actingAsPerson($this->owner())->post("/mypage/sf-inbox/{$item->id}/skip")
            ->assertRedirect('/mypage')->assertSessionHas('sf_status');
        $this->assertSame(SfInboxItem::SKIPPED, $item->refresh()->status);
        $this->assertSame(0, Project::count());
    }

    public function test_本人は見送った商談をマイページから戻せる_他人のは戻せない_登録ずみは戻さない(): void
    {
        SfInboxSample::seed();
        $owner = $this->owner();
        $this->actingAsPerson($owner)->post('/mypage/sf-inbox/'.$this->item(1)->id.'/skip')->assertRedirect('/mypage');

        // 見送った商談は、マイページに畳んで残る（戻すボタンつき）
        $this->actingAsPerson($owner)->get('/mypage')->assertOk()
            ->assertSee('見送った商談（1件）')->assertSee('/mypage/sf-inbox/'.$this->item(1)->id.'/reopen', false);

        // 他人は戻せない
        $this->actingAsPerson($this->other())->post('/mypage/sf-inbox/'.$this->item(1)->id.'/reopen')->assertForbidden();
        $this->assertSame(SfInboxItem::SKIPPED, $this->item(1)->status);

        // 本人は戻せる
        $this->actingAsPerson($owner)->post('/mypage/sf-inbox/'.$this->item(1)->id.'/reopen')
            ->assertRedirect('/mypage')->assertSessionHas('sf_status');
        $this->assertSame(SfInboxItem::WAITING, $this->item(1)->status);

        // 登録ずみは戻さない（戻すと同じ商談から案件が2つできる）
        $this->item(1)->update(['status' => SfInboxItem::REGISTERED]);
        $this->actingAsPerson($owner)->post('/mypage/sf-inbox/'.$this->item(1)->id.'/reopen')->assertSessionHas('sf_error');
        $this->assertSame(SfInboxItem::REGISTERED, $this->item(1)->status);
    }

    public function test_他人あての商談は社員には登録も見送りもできない_管理者でもマイページの入口からは不可(): void
    {
        SfInboxSample::seed();
        $item = $this->item(1);

        $other = $this->other();
        $this->actingAsPerson($other)->post("/mypage/sf-inbox/{$item->id}/register", ['office' => '東京'])->assertForbidden();
        $this->actingAsPerson($other)->post("/mypage/sf-inbox/{$item->id}/skip")->assertForbidden();
        $this->actingAsPerson($this->manager())->post("/mypage/sf-inbox/{$item->id}/register", ['office' => '東京'])->assertForbidden();
        // 社員は受信箱そのものには入れない（今までどおり）
        $this->actingAsPerson($this->owner())->post("/sf-inbox/{$item->id}/register", ['office' => '東京'])->assertForbidden();
        // スタッフはマイページの入口にも入れない
        $staff = PersonFactory::new()->staff()->create(['must_onboard' => false, 'email' => 'sample-sales@example.com.staff']);
        $this->actingAsPerson($staff)->post("/mypage/sf-inbox/{$item->id}/skip")->assertRedirect('/staff-portal');

        $this->assertSame(0, Project::count());
        $this->assertSame(SfInboxItem::WAITING, $item->refresh()->status);
    }

    // ── C. SFの商談へのリンク ──

    public function test_SFから登録した案件だけ商談へのリンクが出る_つなぎ先が空なら出さない(): void
    {
        SfInboxSample::seed();
        $me = $this->manager();
        $r = SfInboxRegister::register($this->item(1), '東京', $me->id);
        $this->assertTrue($r['ok']);
        $pid = $r['project_id'];

        config(['services.salesforce.url' => 'https://example.my.salesforce.com/']);
        $this->actingAsPerson($me)->get('/project-form?project='.urlencode($pid))
            ->assertOk()
            ->assertSee('Salesforceの商談を開く')
            ->assertSee('https://example.my.salesforce.com/006SAMPLE000000001', false)
            ->assertSee($pid);   // ECSの案件番号はそのまま

        // SFから来ていない案件には出さない
        Project::create(['id' => 'P-2027-9999', 'project_name' => '手で登録した案件', 'office' => '東京', 'start_date' => '2027-01-01']);
        $this->actingAsPerson($me)->get('/project-form?project=P-2027-9999')
            ->assertOk()->assertDontSee('Salesforceの商談を開く');

        // つなぎ先が空ならボタンは出さない（どの商談から来たかの一文は出す）
        config(['services.salesforce.url' => '']);
        $this->actingAsPerson($me)->get('/project-form?project='.urlencode($pid))
            ->assertOk()->assertDontSee('Salesforceの商談を開く')
            ->assertSee('この案件はSalesforceの商談『【見本】株式会社サンプル商事__2027-01-20』から登録しました');
        $this->actingAsPerson($me)->get('/project-form?project=P-2027-9999')
            ->assertOk()->assertDontSee('から登録しました');
    }

    // ── D. 11/20 小沼さんの手元確認からの直し ──

    public function test_マイページの商談は営業担当の案件の中_一覧の上に出る_時間はいつも出し残りはぜんぶ見るで開く(): void
    {
        SfInboxSample::seed();
        $this->actingAsPerson($this->owner())->get('/mypage')
            ->assertOk()
            ->assertSeeInOrder(['<h2>営業担当の案件</h2>', 'Salesforceから届いた商談（4件）', 'id="salBody"'], false)
            ->assertSee('集合 ')->assertSee('イベント開始')
            ->assertSee('▼ ぜんぶ見る')->assertSee('金額（参考）')->assertSee('Salesforceの備考')
            // 確認はブラウザの窓ではなく、その場で聞き直す
            ->assertSee('登録しますか？')->assertDontSee('この商談をECSの案件として登録します。よろしいですか？');
    }

    public function test_マイページの拠点ははじめ本人の拠点_本人の拠点が無いときだけ選んでください(): void
    {
        SfInboxSample::seed();
        // SFの担当拠点が大阪・拠点が分からない商談でも、はじめは本人の拠点（東京）
        $html = strstr($this->actingAsPerson($this->owner())->get('/mypage')->assertOk()->getContent(), 'id="sfMine"');
        $html = $html === false ? '' : strstr($html, 'id="salBody"', true);
        $this->assertSame(4, substr_count($html, '<option value="東京" selected>'));
        $this->assertStringNotContainsString('<option value="大阪" selected>', $html);
        $this->assertStringNotContainsString('選んでください</option>', $html);

        $noOffice = PersonFactory::new()->create(['office' => '', 'must_onboard' => false, 'email' => 'sample-sales@example.com']);
        $html2 = strstr($this->actingAsPerson($noOffice)->get('/mypage')->assertOk()->getContent(), 'id="sfMine"');
        $html2 = $html2 === false ? '' : strstr($html2, 'id="salBody"', true);
        $this->assertSame(4, substr_count($html2, '選んでください</option>'));
    }

    public function test_複数日の商談は日数ぶんの案件を連日イベントとして作る_受信箱には初日を入れる(): void
    {
        SfInboxSample::seed();
        $item = $this->item(2);   // 大阪・2027-02-05・2日間・参加300名

        $boss = $this->manager('大阪');
        $r = SfInboxRegister::register($item, null, $boss->id);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('連日イベントとして2日ぶん', $r['message']);

        $this->assertSame(2, Project::count());
        $first = Project::findOrFail($r['project_id']);
        $second = Project::where('parent_project_id', $first->id)->firstOrFail();
        $this->assertNull($first->parent_project_id);
        $this->assertSame('2027-02-05', substr((string) $first->start_date, 0, 10));
        $this->assertSame('2027-02-06', substr((string) $second->start_date, 0, 10));
        $this->assertSame('本番', $second->date_type);
        $this->assertSame($first->guest_count, $second->guest_count);
        $this->assertSame('大阪', $second->office);
        $this->assertFalse((bool) $second->staff_published);
        $this->assertSame($first->id, $item->refresh()->project_id);

        // 二重には作らない
        $this->assertFalse(SfInboxRegister::register($item, null, null)['ok']);
        $this->assertSame(2, Project::count());

        // 2日目の編集画面にも、同じ商談の一文が出る
        $this->actingAsPerson($boss)->get('/project-form?project='.urlencode($second->id))
            ->assertOk()->assertSee('サンプル工業株式会社__2027-02-05』から登録しました', false);
    }

    public function test_日数が多すぎる商談と開催日が空の複数日の商談は初日だけ作る(): void
    {
        SfInboxSample::seed();
        $item = $this->item(1);
        $item->update(['payload' => array_merge($item->payload, ['kaisainissu__c' => 20])]);
        $p = \App\Support\SfOpportunityMap::preview($item->payload);
        $this->assertSame(1, $p['series_days']);
        $this->assertStringContainsString('安全のため登録すると初日の1件だけ', implode('', $p['warnings']));
        $this->assertTrue(SfInboxRegister::register($item, '東京', null)['ok']);
        $this->assertSame(1, Project::count());

        $p2 = \App\Support\SfOpportunityMap::preview(['kaisaibi__c' => null, 'kaisainissu__c' => 3]);
        $this->assertSame(1, $p2['series_days']);

        $p3 = \App\Support\SfOpportunityMap::preview(['kaisaibi__c' => '2027-01-01', 'kaisainissu__c' => 3]);
        $this->assertSame(3, $p3['series_days']);
        $this->assertStringContainsString('登録すると3日ぶんの案件を連日イベントとして作ります', implode('', $p3['warnings']));
    }

    public function test_マイページから登録すると営業担当に本人が入る_受信箱からは今までどおり(): void
    {
        SfInboxSample::seed();
        // メールの前後に空白があり名簿で1人に決まらない人＝押した本人の名前が入る
        $owner = $this->owner();
        $this->actingAsPerson($owner)->post('/mypage/sf-inbox/'.$this->item(1)->id.'/register', ['office' => '東京'])
            ->assertSessionHas('sf_status');
        $this->assertSame([$owner->name], Project::firstOrFail()->sales_owners);

        // 受信箱（管理者）からは、メールで決まらなければ入れない（押した管理者を営業にしない）
        $r = SfInboxRegister::register($this->item(3), '札幌', $this->manager()->id);
        $this->assertEmpty(Project::findOrFail($r['project_id'])->sales_owners);
    }

    public function test_備考にはSFの備考だけを入れる(): void
    {
        SfInboxSample::seed();
        $item = $this->item(1);
        $item->update(['payload' => array_merge($item->payload, ['ECS_Note__c' => '駐車場あり'])]);
        $r = SfInboxRegister::register($item, '東京', null);
        $this->assertSame('駐車場あり', Project::findOrFail($r['project_id'])->note);

        $r2 = SfInboxRegister::register($this->item(3), '札幌', null);
        $this->assertStringNotContainsString('Salesforceの商談から登録', (string) Project::findOrFail($r2['project_id'])->note);
    }
}
