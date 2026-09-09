<?php

namespace Tests\Feature;

use App\Support\ProjectScale;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 集計ダッシュボード（/stats）の「規模別（小型・中型・大型）」と「昨対比」の見張り
 * （2026-09-09 上長要望「リアル／オンラインではなく小型・中型・大型で」「昨対比が出せるとうれしい」）。
 *
 * ⚠ ここで守りたいのは4つ。
 *   ① 大きい数字（KPI）は 合計・小型・中型・大型・のべ出勤数。リアル／オンラインは出さない。
 *   ② 案件規模が空の案件も、どこかに必ず数える（合計 ≠ 小＋中＋大 になると数字を信じてもらえない）。
 *      いまの決まりは「小型に数える」＝正本は App\Support\ProjectScale の1か所。
 *   ③ 空欄を混ぜていることは画面に必ず出す（「うち◯件は案件規模が未入力」）。
 *   ④ **前年のデータが無い期間と、前年0件を区別する。** 区別しないと全部「-100%」になり、
 *      画面の数字そのものが信用されなくなる。
 */
class StatsScaleYoyTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** その月の1日（今月／去年の同じ月）に案件を作る。 */
    private function project(string $ym, ?string $scale, string $name): void
    {
        ProjectFactory::new()->create([
            'start_date' => $ym . '-01', 'status' => '未着手', 'office' => '東京',
            'project_name' => $name, 'scale' => $scale, 'format' => 'リアル',
        ]);
    }

    private function thisMonth(): string
    {
        return Carbon::today()->format('Y-m');
    }

    private function lastYearMonth(): string
    {
        return Carbon::today()->subYear()->format('Y-m');
    }

    /** @return array<string, mixed> */
    private function data(array $query = []): array
    {
        return $this->actingAsPerson($this->admin())
            ->get('/stats?' . http_build_query($query))
            ->assertOk()
            ->original->getData();
    }

    /** 大きい数字は 合計・小型・中型・大型・のべ出勤数（リアル／オンラインは出さない）。 */
    public function test_kpis_are_by_scale_not_by_format(): void
    {
        $this->project($this->thisMonth(), '大型', 'おおきい案件');

        $res = $this->actingAsPerson($this->admin())->get('/stats')->assertOk();

        $res->assertSee('イベント数（合計）')
            ->assertSee('小型')
            ->assertSee('中型')
            ->assertSee('大型')
            // ⚠ KPIの「リアル」「オンライン」は外した（社員別の「リアルD」列は残っている）。
            ->assertDontSee('<div class="k-label">リアル</div>', false)
            ->assertDontSee('<div class="k-label">オンライン</div>', false);
    }

    /** 案件規模が空の案件も必ずどこかに数える（いまの決まり＝小型）。 */
    public function test_projects_without_scale_are_counted(): void
    {
        $this->project($this->thisMonth(), null, '規模なし案件');
        $this->project($this->thisMonth(), '中型', 'ふつうの案件');

        $data = $this->data();

        $this->assertSame(2, $data['totalEvents']);
        // 合計 ＝ 小型＋中型＋大型（どこにも入らない案件を作らない）。
        $this->assertSame(2, array_sum($data['scaleCounts']));
        $this->assertSame(1, $data['scaleCounts'][ProjectScale::UNSET_GOES_TO]);
        // 混ぜたことは必ず知らせる。
        $this->assertSame(1, $data['scaleUnsetCount']);
    }

    /** 規模が未入力の件数は画面にも出す（黙って混ぜない）。 */
    public function test_the_screen_says_how_many_have_no_scale(): void
    {
        $this->project($this->thisMonth(), null, '規模なし案件');

        $this->actingAsPerson($this->admin())->get('/stats')
            ->assertOk()
            ->assertSee('案件規模」が未入力', false);
    }

    /** 昨対比＝1年前の同じ期間と比べる。 */
    public function test_year_over_year_compares_with_the_same_period_last_year(): void
    {
        $this->project($this->thisMonth(), '小型', '今年の案件A');
        $this->project($this->thisMonth(), '大型', '今年の案件B');
        $this->project($this->lastYearMonth(), '小型', '去年の案件');

        $data = $this->data();

        $this->assertTrue($data['lastYear']['hasData']);
        $this->assertSame(1, $data['lastYear']['total']);

        // 合計＝2件（前年1件）＝＋1件・＋100%
        $this->assertTrue($data['yoyTotal']['has']);
        $this->assertSame(1, $data['yoyTotal']['prev']);
        $this->assertSame(1, $data['yoyTotal']['diff']);
        $this->assertSame(100, $data['yoyTotal']['pct']);

        $byScale = collect($data['byScale'])->keyBy('scale');
        // 小型＝1件（前年1件）＝増減なし
        $this->assertSame(0, $byScale['小型']['yoy']['diff']);
        // 大型＝1件（前年0件）＝＋1件。⚠ 前年0件のときは率を出さない（0では割れない）。
        $this->assertSame(1, $byScale['大型']['yoy']['diff']);
        $this->assertNull($byScale['大型']['yoy']['pct']);
    }

    /**
     * ⚠ 前年のデータが無い期間は「前年のデータなし」＝0件あつかいにしない。
     *   0件にすると、去年ECSを使っていなかっただけなのに「-100%」と出てしまう。
     */
    public function test_missing_last_year_is_not_treated_as_zero(): void
    {
        $this->project($this->thisMonth(), '中型', '今年だけの案件');

        $data = $this->data();

        $this->assertFalse($data['lastYear']['hasData']);
        $this->assertFalse($data['yoyTotal']['has']);

        $this->actingAsPerson($this->admin())->get('/stats')
            ->assertOk()
            ->assertSee('前年のデータなし');
    }

    /** CSVにも昨対比が出る（画面と同じ数字）。 */
    public function test_csv_has_the_year_over_year_columns(): void
    {
        $this->project($this->thisMonth(), '小型', '今年の案件');
        $this->project($this->lastYearMonth(), '小型', '去年の案件');

        $csv = $this->actingAsPerson($this->admin())->get('/stats/export.csv')
            ->assertOk()->getContent();

        $this->assertStringContainsString('規模別イベント数（昨対比つき）', $csv);
        $this->assertStringContainsString('前年同期', $csv);
        $this->assertStringContainsString('小型', $csv);
    }
}
