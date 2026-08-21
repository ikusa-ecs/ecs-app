<?php

namespace Tests\Feature;

use App\Support\OfficeOptions;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 拠点ごとの選択肢（集合形式・音響機材・移動車両・運営場所）。2026-08-21 baba要望。
 *
 * 9月から東京・名古屋の2拠点で使い始めるため、東京にしか無いもの（大住・広宣・IKUSAカー）が
 * 他拠点で出ないようにする。まだ設定していない拠点は東京と同じ（既定）が出る＝すぐ使い始められる。
 */
class OfficeOptionsTest extends TestCase
{
    use RefreshDatabase;

    /** 未設定の拠点は既定（＝これまで画面に直書きしていた内容）が出る。 */
    public function test_unset_office_falls_back_to_defaults(): void
    {
        $this->assertSame(OfficeOptions::DEFAULTS['transport'], OfficeOptions::get('名古屋', 'transport'));
        $this->assertContains('大住', OfficeOptions::get('名古屋', 'assembly_type'));
    }

    /** 拠点ごとに書き換えられる。書き換えた拠点だけが変わる。 */
    public function test_saving_changes_only_that_office(): void
    {
        OfficeOptions::put('名古屋', 'transport', "電車\nレンタカー");

        $this->assertSame(['電車', 'レンタカー'], OfficeOptions::get('名古屋', 'transport'));
        $this->assertSame(OfficeOptions::DEFAULTS['transport'], OfficeOptions::get('東京', 'transport'));
    }

    /** 空行・前後の空白・重複は落として保存する。 */
    public function test_text_is_cleaned_up(): void
    {
        OfficeOptions::put('名古屋', 'assembly_type', "  会場現地  \n\n駅\n駅\n");

        $this->assertSame(['会場現地', '駅'], OfficeOptions::get('名古屋', 'assembly_type'));
    }

    /** 運営場所には、ほかの拠点への「○○依頼」が自動で足される。 */
    public function test_operation_places_add_other_offices(): void
    {
        $list = OfficeOptions::operationPlaces('東京', ['東京', '名古屋', '北海道']);

        $this->assertContains('現地', $list);
        $this->assertContains('名古屋依頼', $list);
        $this->assertContains('北海道依頼', $list);
        $this->assertNotContains('東京依頼', $list, '自分の拠点への依頼は出さない');
    }

    /** マスタ管理から保存できる（管理者以上）。 */
    public function test_master_screen_can_save(): void
    {
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);

        $this->actingAsPerson($admin)
            ->post('/masters/office-options', [
                'office' => '名古屋',
                'kinds'  => [
                    'assembly_type'   => "会場現地\n駅",
                    'audio_equipment' => '会場音響',
                    'transport'       => '電車',
                    'operation_place' => '現地',
                ],
            ])
            ->assertRedirect('/masters?office=' . urlencode('名古屋') . '#office-options');

        $this->assertSame(['会場現地', '駅'], OfficeOptions::get('名古屋', 'assembly_type'));
    }

    /** 案件登録画面に、全拠点ぶんの選択肢が渡っている。 */
    public function test_project_form_receives_the_map(): void
    {
        $emp = PersonFactory::new()->create(['office' => '東京']);

        $this->actingAsPerson($emp)->get('/project-form')
            ->assertOk()
            ->assertSee('ECS_OFFICE_OPTIONS');
    }
}
