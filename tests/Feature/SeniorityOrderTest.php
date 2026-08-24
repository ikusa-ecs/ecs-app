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

    /** D／SD／物品担当のプルダウンは「その拠点のイベプラ」が先頭に来る。 */
    public function test_director_picker_puts_office_planners_first(): void
    {
        // 名前順だけなら「あ…」が先に来る並びにしておき、拠点＋イベプラが勝つことを見る。
        PersonFactory::new()->create([
            'id' => 'E-101', 'name' => 'あおやま', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'department' => 'セールス', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'E-102', 'name' => 'わたなべ', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'department' => 'イベプラ', 'must_onboard' => false,
        ]);
        PersonFactory::new()->create([
            'id' => 'E-103', 'name' => 'いのうえ', 'role' => 'employee', 'permission' => 'employee',
            'office' => '大阪', 'department' => 'イベプラ', 'must_onboard' => false,
        ]);
        $me = PersonFactory::new()->create([
            'id' => 'E-104', 'name' => 'うえだ', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'department' => 'イベプラ', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/projects')->assertOk()->getContent();

        $at = [];
        foreach (['E-101', 'E-102', 'E-103', 'E-104'] as $id) {
            $at[$id] = strpos($html, '"id":"'.$id.'"');
            $this->assertNotFalse($at[$id], $id.' がプルダウンに出ていない');
        }

        // 東京のイベプラ（E-102 わたなべ / E-104 うえだ）が、他より先。
        $this->assertTrue($at['E-104'] < $at['E-101'], '自拠点のイベプラが他部署より先に出ること');
        $this->assertTrue($at['E-102'] < $at['E-101'], '名前が後ろでも自拠点のイベプラが先に出ること');
        $this->assertTrue($at['E-102'] < $at['E-103'], '自拠点のイベプラが他拠点のイベプラより先に出ること');
        // 先頭グループの中は氏名順（うえだ → わたなべ）。
        $this->assertTrue($at['E-104'] < $at['E-102'], '先頭グループの中は氏名順');
    }

    /** 営業担当のプルダウンは社歴順。 */
    public function test_sales_owner_picker_is_ordered_by_seniority(): void
    {
        PersonFactory::new()->create([
            'id' => 'E-201', 'name' => 'あたらしい人', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'department' => 'セールス', 'hire_date' => '2025-04-01', 'must_onboard' => false,
        ]);
        $me = PersonFactory::new()->create([
            'id' => 'E-202', 'name' => 'ふるい人', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'department' => 'セールス', 'hire_date' => '2012-04-01', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/project-form')->assertOk()->getContent();

        // 営業担当は名前の配列で渡される（日本語は \uXXXX になるので JSON 表記で探す）。
        $old = strpos($html, json_encode('ふるい人', JSON_UNESCAPED_SLASHES));
        $new = strpos($html, json_encode('あたらしい人', JSON_UNESCAPED_SLASHES));
        $this->assertNotFalse($old, '社歴の長い人が営業担当の候補に出ていない');
        $this->assertNotFalse($new, '新しい人が営業担当の候補に出ていない');
        $this->assertTrue($old < $new, '営業担当は社歴順（古い人が先）に並ぶこと');
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
