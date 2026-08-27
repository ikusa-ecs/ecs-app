<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員名簿・スタッフ名簿を「拠点ごと」に見られるようにした（2026-08-25 baba要望）。
 *
 * 作り＝画面の絞り込み（他の絞り込みと同じ並び）。
 *  ・既定は自分の拠点。「拠点：すべて」も選べる
 *    （他拠点へヘルプに行く／来てもらう運用があるので、他拠点の人を探せなくならないようにする）
 *  ・拠点名は画面に書かず、拠点マスタ（Office）から作る＝拠点が増えても直さなくてよい
 */
class RosterOfficeFilterTest extends TestCase
{
    use RefreshDatabase;

    /** 両方の名簿に「拠点の絞り込み」があり、既定が自分の拠点になっている。 */
    public function test_both_rosters_have_office_filter_defaulting_to_own_office(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-200', 'office' => '東北', 'permission' => 'manager', 'must_onboard' => false,
        ]);

        foreach (['/employees', '/staff'] as $url) {
            $html = $this->actingAsPerson($me)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('id="fOffice"', $html, "{$url} に拠点の絞り込みがあること");
            // 既定＝自分の拠点。画面へは @json で渡るので、日本語はエスケープされた形になる。
            $this->assertStringContainsString(
                'window.ECS_MY_OFFICE = '.json_encode('東北', JSON_UNESCAPED_SLASHES), $html);
            // 選択肢は拠点マスタから作る（画面に拠点名を直書きしない）。
            $this->assertStringContainsString('window.ECS_OFFICES = ', $html);
            $this->assertStringContainsString(json_encode('東北', JSON_UNESCAPED_SLASHES), $html);
        }
    }

    /** 稼働状況タブも拠点で絞れるよう、人ごとの拠点が渡っている。 */
    public function test_staff_status_rows_carry_office(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-201', 'office' => '東京', 'permission' => 'manager', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'S-201', 'name' => '東北のスタッフ', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東北', 'must_onboard' => false,
        ]);

        $status = $this->actingAsPerson($me)->get('/staff')->assertOk()->viewData('status');

        $row = collect($status)->firstWhere('id', 'S-201');
        $this->assertNotNull($row, '稼働状況にスタッフが並ぶこと');
        $this->assertSame('東北', $row['office']);
    }

    /**
     * 社員の出勤可能日（全社員の一覧タブ）も拠点で絞れる（2026-08-26 baba要望）。
     * 名簿と同じ作り＝既定は自分の拠点・選択肢は拠点マスタから・人ごとに拠点を渡す。
     */
    public function test_employee_availability_can_filter_by_office(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-210', 'name' => '東北の社員', 'office' => '東北',
            'permission' => 'manager', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'E-211', 'name' => '東京の社員', 'office' => '東京',
            'permission' => 'employee', 'must_onboard' => false,
        ]);

        $res = $this->actingAsPerson($me)->get('/employee-availability')->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('id="ovOffice"', $html, '拠点の絞り込みがあること');
        $this->assertStringContainsString(
            'window.ECS_MY_OFFICE = '.json_encode('東北', JSON_UNESCAPED_SLASHES), $html,
            '既定は自分の拠点であること');
        $this->assertStringContainsString('window.ECS_OFFICES = ', $html,
            '選択肢は拠点マスタから作ること（画面に拠点名を直書きしない）');

        // 表を絞るには人ごとの拠点が要る。他拠点の人も画面には渡す（絞り込みで出し分けるだけ）。
        $employees = collect($res->viewData('employees'));
        $this->assertSame('東北', $employees->firstWhere('id', 'E-210')['office']);
        $this->assertSame('東京', $employees->firstWhere('id', 'E-211')['office']);
        // 「先頭＝自分」の決まりは変えていない（絞り込みは画面側で番号を持ち回す）。
        $this->assertSame('E-210', $employees->first()['id']);
    }

    /** 一般社員でも「拠点：すべて」で他拠点の人を探せる（＝隠すのではなく絞り込み）。 */
    public function test_roster_still_contains_other_office_people(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-202', 'office' => '東京', 'permission' => 'employee', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'S-202', 'name' => '大阪のスタッフ', 'role' => 'staff', 'permission' => 'staff',
            'office' => '大阪', 'must_onboard' => false,
        ]);

        $people = $this->actingAsPerson($me)->get('/staff')->assertOk()->viewData('people');

        $this->assertNotNull(collect($people)->firstWhere('id', 'S-202'),
            '他拠点の人も画面には渡っている（絞り込みで出し分けるだけ）');
    }

    /**
     * スタッフ名簿を五十音順（ふりがな順）でも並べられる（2026-08-27 baba要望）。
     * 既定は今までどおり「経験回数の多い順」。
     */
    public function test_staff_roster_has_kana_sort(): void
    {
        $me = PersonFactory::new()->create([
            'role' => 'employee', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        PersonFactory::new()->staff()->create([
            'id' => 'S-101', 'name' => '渡辺 花子', 'name_kana' => 'わたなべ はなこ', 'office' => '東京',
        ]);

        $html = $this->actingAsPerson($me)->get('/staff')->assertOk()->getContent();

        $this->assertStringContainsString('id="fRosterSort"', $html, '並びのプルダウン');
        $this->assertStringContainsString('五十音順', $html);
        $this->assertStringContainsString('rosterSort()', $html);
        // ふりがなが画面まで渡っていること（渡っていないと並べられない）。
        // ⚠ 画面のJSONでは列名 name_kana ではなく "kana" で渡している。
        $this->assertStringContainsString('"kana"', $html);
    }
}
