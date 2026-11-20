<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use App\Models\SfProduct;
use Database\Factories\ContentFactory;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 小さな3つの機能（2026-11-20 小沼さん）。
 *  1. マスタのコンテンツ一覧：行の「▼」で、つながっているSFの商品名と18桁の番号を開いて見られる。
 *  2. スタッフ画面：東北の案件を東京のスタッフにも出すので、「東北の案件のみ」で絞れる（案件の拠点を渡す）。
 *  3. オンラインツールの選択肢に remo を足した（案件フォーム・SFからの取込の変換表）。
 */
class SfSmallFeaturesTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. マスタ：SFの商品 ──

    /** つながっているSFの商品は、2つ以上でも全部、商品名と18桁の番号が出る。無視・未対応の商品は出さない。 */
    public function test_masters_show_linked_sf_products_per_content(): void
    {
        ContentFactory::new()->create(['id' => 'CT-001', 'content_name' => '水合戦']);
        ContentFactory::new()->create(['id' => 'CT-002', 'content_name' => '運動会']);
        SfProduct::create(['sf_product_id' => '01t000000000001AAA', 'name' => '水合戦（スタンダード）', 'handling' => SfProduct::CONTENT, 'content_id' => 'CT-001']);
        SfProduct::create(['sf_product_id' => '01t000000000002AAA', 'name' => '水合戦（あそ研）', 'handling' => SfProduct::CONTENT, 'content_id' => 'CT-001']);
        // 扱いが「無視する」なら content_id が残っていても出さない。
        SfProduct::create(['sf_product_id' => '01t000000000003AAA', 'name' => '水合戦 機材オプション', 'handling' => SfProduct::IGNORE, 'content_id' => 'CT-001']);

        $res = $this->actingAsPerson(PersonFactory::new()->create())->get('/masters')->assertOk();

        $map = $res->original->getData()['sfProductsByContent'];
        $this->assertCount(2, $map['CT-001'], 'つながりが2つなら2つとも');
        $this->assertFalse($map->has('CT-002'), 'つながりが無いコンテンツは空');

        $res->assertSee('水合戦（スタンダード）')
            ->assertSee('01t000000000001AAA')
            ->assertSee('01t000000000002AAA')
            ->assertDontSee('01t000000000003AAA')
            ->assertSee('class="m-sf" hidden', false);   // ふだんは畳んでおく
    }

    // ── 2. スタッフ画面：東北の案件のみ ──

    /** 東京のスタッフに、東京の案件と（ヘルプで共有された）東北の案件が並び、それぞれ拠点が渡る。 */
    public function test_staff_portal_jobs_carry_office_for_tohoku_filter(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $soon = Carbon::today()->addDays(3)->format('Y-m-d');

        $tokyo = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $soon]);
        $blank = ProjectFactory::new()->published()->create(['office' => null, 'start_date' => $soon]);
        $tohoku = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $soon, 'published_offices' => ['東京'],
        ]);
        ProjectShare::create(['project_id' => $tohoku->id, 'office' => '東京', 'kind' => 'ヘルプ']);

        $res = $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
        $jobs = collect($res->original->getData()['recruitJobs'])->keyBy('id');

        $this->assertSame('東京', $jobs[$tokyo->id]['office']);
        $this->assertSame('東京', $jobs[$blank->id]['office'], '拠点が空の案件は東京あつかい');
        $this->assertSame('東北', $jobs[$tohoku->id]['office'], '東北の案件が東京のスタッフにも出て、東北と分かる');

        $res->assertSee('id="jfTohoku"', false)->assertSee('東北の案件のみ');
    }

    // ── 3. オンラインツール remo ──

    /** 案件フォームの選択肢に remo がある。 */
    public function test_project_form_has_remo_option(): void
    {
        $this->actingAsPerson(PersonFactory::new()->create())->get('/project-form')
            ->assertOk()
            ->assertSee('name="onlineTool" value="remo"', false);
    }

    /** SFの「remo」は、ECSの remo に変換される（変換表に無いと「ツールなし」扱いで落ちる）。 */
    public function test_sf_map_knows_remo(): void
    {
        $ref = new \ReflectionClassConstant(\App\Support\SfOpportunityMap::class, 'ONLINE_TOOLS');
        $tools = $ref->getValue();
        $this->assertSame('remo', $tools['remo'] ?? null);
    }
}
