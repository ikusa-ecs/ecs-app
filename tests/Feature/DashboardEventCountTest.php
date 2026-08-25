<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * トップの「今月の件数集計」（2026-08-25 修正）。
 *
 * ⚠ もともとは実施形態の「イベント東(リアル)」というカッコ付きの文字だけを見て、
 *   カッコの前を拠点・中を種別として数えていた。2026-07-31 の全拠点対応で
 *   **拠点は projects.office に移った**のに集計だけ古いままで、カッコの無い今のデータ
 *   （実施形態＝「リアル」だけ）では拠点も種別も読めず、**全部「その他」**になっていた。
 *   （CSVで9月分を取り込んだ baba が発見）
 *
 * 直したあと：拠点＝登録拠点／種別＝実施形態。昔の形のデータも読めるようにしてある。
 */
class DashboardEventCountTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 件数集計に「登録拠点」が渡っている（これが無いと拠点で数えられない）。 */
    public function test_cases_carry_office(): void
    {
        ProjectFactory::new()->create([
            'id' => 'P-2026-9001', 'office' => '東京', 'format' => 'リアル',
            'start_date' => '2026-09-01', 'status' => '確定',
        ]);

        $cases = $this->actingAsPerson($this->manager())->get('/dashboard')->assertOk()->viewData('cases');

        $row = collect($cases)->firstWhere('id', 'P-2026-9001');
        $this->assertNotNull($row);
        $this->assertSame('東京', $row['office'], '拠点が渡ること');
        $this->assertSame('リアル', $row['format'], '実施形態が渡ること');
    }

    /** 拠点の並びは拠点マスタから渡す（画面に拠点名を直書きしない）。 */
    public function test_office_list_is_passed_from_the_master(): void
    {
        $res = $this->actingAsPerson($this->manager())->get('/dashboard')->assertOk();

        $offices = $res->viewData('offices');
        $this->assertContains('東京', $offices);

        $html = $res->getContent();
        $this->assertStringContainsString('window.ECS_COUNT_OFFICES', $html);
        // 古い決め打ち（イベント東 …）が残っていないこと＝これがあると拠点が読めない。
        $this->assertStringNotContainsString("'イベント東','イベント東北'", $html);
    }

    /** 実施形態が空の案件でも、拠点は登録拠点から分かる（＝「その他」に落ちない）。 */
    public function test_project_without_format_still_has_office(): void
    {
        ProjectFactory::new()->create([
            'id' => 'P-2026-9002', 'office' => '東京', 'format' => null,
            'start_date' => '2026-09-02', 'status' => '確定',
        ]);

        $cases = $this->actingAsPerson($this->manager())->get('/dashboard')->assertOk()->viewData('cases');

        $row = collect($cases)->firstWhere('id', 'P-2026-9002');
        $this->assertSame('東京', $row['office']);
        $this->assertSame('', $row['format']);
    }
}
