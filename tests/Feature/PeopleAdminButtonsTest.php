<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 名簿の「退職にする」「削除」ボタンの出し分け（2026-08-24）。
 *
 * Administrator にだけ出す。自分自身の行には出さない。
 * ※ 画面が真っ白になる（Bladeの罠）ことがあるので、実際に開いて200が返るかも見る。
 */
class PeopleAdminButtonsTest extends TestCase
{
    use RefreshDatabase;

    /** Administrator には社員名簿・スタッフ名簿の両方にボタンが出る。 */
    public function test_admin_sees_delete_buttons(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'S-001', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false,
        ]);

        foreach (['/employees', '/staff'] as $url) {
            $html = $this->actingAsPerson($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('ECS_CAN_MANAGE_PEOPLE = true', $html, $url.' で旗が立つこと');
            $this->assertStringContainsString('data-del-id', $html, $url.' に削除ボタンの受け口があること');
        }
    }

    /** 管理者（manager）と一般社員にはボタンが出ない。 */
    public function test_non_admin_does_not_see_delete_buttons(): void
    {
        foreach (['manager' => 'E-011', 'employee' => 'E-012'] as $perm => $id) {
            $actor = PersonFactory::new()->create([
                'id' => $id, 'permission' => $perm, 'office' => '東京', 'must_onboard' => false,
            ]);

            foreach (['/employees', '/staff'] as $url) {
                $html = $this->actingAsPerson($actor)->get($url)->assertOk()->getContent();
                $this->assertStringContainsString('ECS_CAN_MANAGE_PEOPLE = false', $html, $url.' で旗が立たないこと');
            }
        }
    }

    /** 在籍・退職の状態が画面へ渡っている（退職バッジ・ボタンの文言に使う）。 */
    public function test_active_flag_is_passed_to_views(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'employee', 'office' => '東京',
            'must_onboard' => false, 'active' => false,
        ]);

        $html = $this->actingAsPerson($admin)->get('/employees')->assertOk()->getContent();
        $this->assertStringContainsString('"active":false', $html);
    }
}
