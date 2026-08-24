<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員名簿の並びを「社歴順（入社日の古い人が上）」にした（2026-08-24 baba）。
 *
 * なぜ＝社長・役員など社歴の長い人を上に出したい。
 * 社員番号（E-001…）は登録した順に振られるだけで社歴とは関係がなく、
 * 番号は他のデータが人を指す名札なので付け替えない。並び順で解決する。
 *
 * あわせて、入社年月日は「発行する管理者」ではなく「本人」が
 * 初回ログインの初期設定で入れる方式にした（管理者には分からないため）。
 */
class SeniorityOrderTest extends TestCase
{
    use RefreshDatabase;

    private function emp(string $id, string $name, ?string $hire): Person
    {
        return PersonFactory::new()->create([
            'id' => $id,
            'name' => $name,
            'hire_date' => $hire,
            'role' => 'employee',
            'permission' => 'admin',
            'office' => '東京',
            'must_onboard' => false,
        ]);
    }

    /** 名簿は入社日の古い人が上。入社日が未入力の人は末尾。 */
    public function test_employee_list_is_ordered_by_seniority(): void
    {
        // 登録した順（＝番号順）とは違う社歴にしておく。
        $this->emp('E-001', 'あとから入った人', '2024-04-01');
        $this->emp('E-002', '社長', '2010-04-01');
        $this->emp('E-003', '入社日わからない人', null);
        $me = $this->emp('E-004', '中堅の人', '2018-04-01');

        $html = $this->actingAsPerson($me)->get('/employees')->assertOk()->getContent();

        // 画面へは @json で渡され、日本語は \uXXXX に変換される。並びの確認は社員番号で行う。
        $at = [];
        foreach (['E-002', 'E-004', 'E-001', 'E-003'] as $id) {
            $at[$id] = strpos($html, '"id":"'.$id.'"');
            $this->assertNotFalse($at[$id], $id.' が名簿に出ていない');
        }

        $this->assertTrue($at['E-002'] < $at['E-004'], '社長（2010年入社）が中堅（2018年）より先に出ること');
        $this->assertTrue($at['E-004'] < $at['E-001'], '中堅（2018年）があとから入った人（2024年）より先に出ること');
        $this->assertTrue($at['E-001'] < $at['E-003'], '入社日が未入力の人は末尾に来ること');
    }

    /** 権限一覧（管理コンソール）も同じ社歴順。 */
    public function test_admin_console_is_ordered_by_seniority(): void
    {
        $this->emp('E-001', 'あとから入った人', '2024-04-01');
        $me = $this->emp('E-002', '社長', '2010-04-01');

        $html = $this->actingAsPerson($me)->get('/admin-console')->assertOk()->getContent();

        $this->assertTrue(
            strpos($html, '社長') < strpos($html, 'あとから入った人'),
            '権限一覧も社歴順に並ぶこと'
        );
    }

    /** アカウント発行の画面では入社日を聞かない（発行する側には分からないため）。 */
    public function test_account_form_has_no_hire_date_field(): void
    {
        $me = $this->emp('E-001', '管理者', '2010-04-01');

        $this->actingAsPerson($me)->get('/account-new')
            ->assertOk()
            ->assertDontSee('name="hire_date"', false);
    }

    /** 初回ログインの初期設定で、本人が入れた入社年月日が保存される。 */
    public function test_onboarding_saves_hire_date(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-500', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => true, 'hire_date' => null,
        ]);

        $this->actingAsPerson($me)->post('/onboarding', [
            'name' => $me->name,
            'email' => $me->email,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            'hire_date' => '2019-04-01',
        ])->assertRedirect();

        $this->assertSame('2019-04-01', $me->fresh()->hire_date->format('Y-m-d'));
        $this->assertFalse((bool) $me->fresh()->must_onboard);
    }

    /** 社員は入社年月日が必須（名簿を社歴順に並べるため）。 */
    public function test_onboarding_requires_hire_date_for_employee(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-501', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => true, 'hire_date' => null,
        ]);

        $this->actingAsPerson($me)->post('/onboarding', [
            'name' => $me->name,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertSessionHasErrors('hire_date');
    }

    /** スタッフは任意（いつから働いているか分からないこともあるため）。 */
    public function test_onboarding_hire_date_is_optional_for_staff(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'S-500', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => true, 'hire_date' => null,
        ]);

        $this->actingAsPerson($me)->post('/onboarding', [
            'name' => $me->name,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertSessionHasNoErrors();
    }
}
