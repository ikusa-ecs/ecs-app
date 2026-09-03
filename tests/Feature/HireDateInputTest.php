<?php

namespace Tests\Feature;

use App\Support\HireDate;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 入社年月日を「年・月・日の3つのプルダウン」で入れられること（2026-09-03 baba要望「入力しにくい」）。
 *
 * ⚠ もとは `<input type="date">` 1個で、カレンダーが今月から開くため
 *   2018年まで戻すのに「前の月」を何十回も押すことになっていた。
 * ⚠ 3つを1本の日付に組み立て直すのはサーバー側1か所（NormalizeHireDate）。
 *   ここが壊れると、どの画面から入れても静かに保存されなくなるので見張る。
 */
class HireDateInputTest extends TestCase
{
    use RefreshDatabase;

    private function emp(string $id, string $perm = 'admin')
    {
        return PersonFactory::new()->create([
            'id' => $id, 'role' => 'employee', 'permission' => $perm,
            'office' => '東京', 'must_onboard' => false, 'hire_date' => null,
        ]);
    }

    private function staff(string $id)
    {
        return PersonFactory::new()->create([
            'id' => $id, 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false, 'hire_date' => null,
        ]);
    }

    /** 年・月・日を選んで送ると、1本の日付になって保存される。 */
    public function test_three_dropdowns_are_saved_as_one_date(): void
    {
        $me = $this->emp('E-HD1');

        $this->actingAsPerson($me)->post('/profile/basic', [
            'name' => $me->name,
            'hire_y' => '2019',
            'hire_m' => '4',
            'hire_d' => '15',
        ])->assertRedirect();

        $this->assertSame('2019-04-15', $me->refresh()->hire_date?->format('Y-m-d'));
    }

    /** 日を選ばなくても「1日」として保存される（日にちが分からない人がいるため）。 */
    public function test_the_day_can_be_left_out_and_becomes_the_first(): void
    {
        $me = $this->emp('E-HD2');

        $this->actingAsPerson($me)->post('/profile/basic', [
            'name' => $me->name,
            'hire_y' => '2022',
            'hire_m' => '10',
            'hire_d' => '',
        ])->assertRedirect();

        $this->assertSame('2022-10-01', $me->refresh()->hire_date?->format('Y-m-d'));
    }

    /**
     * 年だけ選んで月を選び忘れたら、保存せずに「月を選んで」と出す。
     * ⚠ ここで勝手に1月にすると、社歴の並び順が静かに何か月もずれる。
     */
    public function test_a_year_without_a_month_is_refused(): void
    {
        $me = $this->emp('E-HD3');
        $me->update(['hire_date' => '2020-05-01']);

        $this->actingAsPerson($me)->post('/profile/basic', [
            'name' => $me->name,
            'hire_y' => '2019',
            'hire_m' => '',
            'hire_d' => '1',
        ])->assertSessionHasErrors('hire_date');

        $this->assertSame('2020-05-01', $me->refresh()->hire_date?->format('Y-m-d'), '止めたはずなのに書き換わっています。');
    }

    /** 「— 年 —」に戻すと未入力に戻せる。 */
    public function test_choosing_no_year_clears_the_date(): void
    {
        $me = $this->emp('E-HD4');
        $me->update(['hire_date' => '2020-05-01']);

        $this->actingAsPerson($me)->post('/profile/basic', [
            'name' => $me->name,
            'hire_y' => '',
            'hire_m' => '',
            'hire_d' => '1',
        ])->assertRedirect();

        $this->assertNull($me->refresh()->hire_date);
    }

    /** 名簿（管理者が直す画面）からも3つで送れる。 */
    public function test_the_employee_list_can_save_the_three_dropdowns(): void
    {
        $me = $this->emp('E-HD5');
        $other = $this->emp('E-HD6');

        $this->actingAsPerson($me)
            ->postJson('/employees/'.$other->id.'/profile', [
                'hire_y' => '2021', 'hire_m' => '7', 'hire_d' => '1',
            ])->assertOk();

        $this->assertSame('2021-07-01', $other->refresh()->hire_date?->format('Y-m-d'));
    }

    /** スタッフ画面からも3つで送れる。 */
    public function test_the_staff_screen_can_save_the_three_dropdowns(): void
    {
        $staff = $this->staff('S-HD1');

        $this->actingAsPerson($staff)
            ->postJson('/staff-portal/profile', ['hire_y' => '2023', 'hire_m' => '11', 'hire_d' => '3'])
            ->assertOk();

        $this->assertSame('2023-11-03', $staff->refresh()->hire_date?->format('Y-m-d'));
    }

    /** 前からの送り方（hire_date 1本）も、これまでどおり通る（CSV取込などが壊れないこと）。 */
    public function test_the_old_single_field_still_works(): void
    {
        $me = $this->emp('E-HD7');

        $this->actingAsPerson($me)->post('/profile/basic', [
            'name' => $me->name,
            'hire_date' => '2018-09-01',
        ])->assertRedirect();

        $this->assertSame('2018-09-01', $me->refresh()->hire_date?->format('Y-m-d'));
    }

    /** 組み立ての決まり（正本）。 */
    public function test_the_rule_for_putting_the_three_together(): void
    {
        $this->assertSame('2024-04-01', HireDate::compose('2024', '4', '1'));
        $this->assertSame('2024-04-01', HireDate::compose('2024', '4', ''));
        $this->assertNull(HireDate::compose('', '4', '1'));
        $this->assertSame(HireDate::INCOMPLETE, HireDate::compose('2024', '', '1'));
        // 2月30日のような「無い日」は、その月の最後の日に寄せる（選び間違いで保存できないより親切）。
        $this->assertSame('2025-02-28', HireDate::compose('2025', '2', '30'));

        $this->assertSame(['y' => '2019', 'm' => '4', 'd' => '15'], HireDate::parts('2019-04-15'));
        $this->assertSame(['y' => '', 'm' => '', 'd' => ''], HireDate::parts(null));
    }

    /** 選べる年に、生まれ年（1990年代）が入っていないこと＝生年月日を入れる事故の予防。 */
    public function test_birth_years_cannot_be_chosen(): void
    {
        $years = HireDate::years();

        $this->assertNotContains(1990, $years);
        $this->assertContains((int) date('Y'), $years);
        $this->assertContains(2018, $years, 'いま登録されている一番古い入社（2018年）が選べません。');
    }

    /** 画面に3つのプルダウンが出ていること（欄が消えたら誰も入れられない）。 */
    public function test_the_screens_show_the_dropdowns(): void
    {
        $me = $this->emp('E-HD8');

        $this->actingAsPerson($me)->get('/employees')
            ->assertOk()
            ->assertSee('ECS_HIRE_YEARS', false)
            ->assertSee('function yearOptions', false)
            ->assertSee("body.append('hire_y'", false);

        $staff = $this->staff('S-HD2');

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('id="pfHireY"', false)
            ->assertSee('function hireSetFromDate', false);
    }
}
