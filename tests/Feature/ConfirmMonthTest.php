<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * その月ぶんの社員をまとめて確定にする（2026-09-03 baba要望）。
 *
 * 【使う場面】D決めが終わってOKが出て、セールスにも共有した → その月ぶんを確定にする。
 *   ⚠ 案件を1つずつ開いて確定にしていくのが手間だった、が背景。
 *
 * ⚠ スタッフは触らない。まとめて確定にすると、まだ声を掛けていない方の画面に
 *   案件が出てしまい、公開の段取りが崩れる。
 * ⚠ 押す前に「誰が確定になるか」を人に見せる（dry=1 で書き込まずに数と名前を返す）。
 * ⚠ 他拠点の案件は触らない。
 */
class ConfirmMonthTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        // 月をまたぐ取り違えを見るため、月の中ほどの日を使う。
        $this->day = Carbon::today()->startOfMonth()->addDays(14);
    }

    private function person(string $id, string $role, string $office = '東京'): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $id.'さん', 'role' => $role,
            'permission' => $role === 'staff' ? 'staff' : 'employee',
            'office' => $office, 'must_onboard' => false, 'active' => true,
        ]);
    }

    private function project(string $id, string $office = '東京', ?Carbon $day = null): void
    {
        ProjectFactory::new()->create([
            'id' => $id, 'project_name' => $id, 'office' => $office, 'status' => '調整中',
            'start_date' => ($day ?? $this->day)->format('Y-m-d'), 'required_count' => 5,
        ]);
    }

    private function assign(string $projectId, string $staffId, ?Carbon $day = null): Assignment
    {
        return Assignment::create([
            'project_id' => $projectId, 'staff_id' => $staffId, 'role' => 'D',
            'date' => ($day ?? $this->day)->format('Y-m-d'), 'status' => '仮',
        ]);
    }

    private function ym(?Carbon $day = null): string
    {
        return ($day ?? $this->day)->format('Y-m');
    }

    /** 押す前に、何名・誰が確定になるかが分かること（書き込まない）。 */
    public function test_a_dry_run_tells_who_would_be_confirmed_without_saving(): void
    {
        $me = $this->person('E-ME', 'employee');
        $this->person('E-A', 'employee');
        $this->project('P-A');
        $a = $this->assign('P-A', 'E-A');

        $this->actingAsPerson($me)
            ->postJson('/assignments/confirm-month', ['ym' => $this->ym(), 'only' => 'employee', 'dry' => 1])
            ->assertOk()
            ->assertJson(['ok' => true, 'confirmed' => 1, 'dry' => true, 'names' => ['E-Aさん']]);

        $this->assertSame('仮', $a->refresh()->status, '見るだけのはずが書き込まれています。');
    }

    /** その月の社員だけが確定になり、スタッフは仮のまま残ること。 */
    public function test_only_employees_of_that_month_are_confirmed(): void
    {
        $me = $this->person('E-ME', 'employee');
        $this->person('E-A', 'employee');
        $this->person('S-A', 'staff');
        $this->project('P-A');
        $emp = $this->assign('P-A', 'E-A');
        $staff = $this->assign('P-A', 'S-A');

        $this->actingAsPerson($me)
            ->postJson('/assignments/confirm-month', ['ym' => $this->ym(), 'only' => 'employee'])
            ->assertOk()
            ->assertJson(['ok' => true, 'confirmed' => 1]);

        $this->assertSame('確定', $emp->refresh()->status);
        $this->assertSame('仮', $staff->refresh()->status, 'スタッフまで確定になっています（公開の段取りが崩れます）。');
    }

    /** 別の月は触らないこと。 */
    public function test_other_months_are_left_alone(): void
    {
        $me = $this->person('E-ME', 'employee');
        $this->person('E-A', 'employee');
        $next = $this->day->copy()->addMonthNoOverflow();
        $this->project('P-A');
        $this->project('P-NEXT', '東京', $next);
        $thisMonth = $this->assign('P-A', 'E-A');
        $nextMonth = $this->assign('P-NEXT', 'E-A', $next);

        $this->actingAsPerson($me)
            ->postJson('/assignments/confirm-month', ['ym' => $this->ym()])
            ->assertOk()
            ->assertJson(['confirmed' => 1]);

        $this->assertSame('確定', $thisMonth->refresh()->status);
        $this->assertSame('仮', $nextMonth->refresh()->status, '別の月まで確定になっています。');
    }

    /** 他拠点の案件は触らないこと。 */
    public function test_other_office_projects_are_left_alone(): void
    {
        $me = $this->person('E-ME', 'employee', '東京');
        $this->person('E-A', 'employee');
        $this->project('P-OSA', '大阪');
        $a = $this->assign('P-OSA', 'E-A');

        $this->actingAsPerson($me)
            ->postJson('/assignments/confirm-month', ['ym' => $this->ym()])
            ->assertOk()
            ->assertJson(['confirmed' => 0]);

        $this->assertSame('仮', $a->refresh()->status);
    }

    /** 名簿に無いIDは巻き込まないこと。 */
    public function test_unknown_ids_are_left_alone(): void
    {
        $me = $this->person('E-ME', 'employee');
        $this->project('P-A');
        $ghost = $this->assign('P-A', 'X-NOBODY');

        $this->actingAsPerson($me)
            ->postJson('/assignments/confirm-month', ['ym' => $this->ym()])
            ->assertOk()
            ->assertJson(['confirmed' => 0]);

        $this->assertSame('仮', $ghost->refresh()->status);
    }

    /** 月の形が違うときは止めること。 */
    public function test_a_bad_month_is_refused(): void
    {
        $me = $this->person('E-ME', 'employee');

        $this->actingAsPerson($me)
            ->postJson('/assignments/confirm-month', ['ym' => '2026/09'])
            ->assertStatus(422);
    }

    /** D決めの画面にボタンと仕掛けがあること（消えたら押せない）。 */
    public function test_the_button_is_on_the_director_board(): void
    {
        $me = $this->person('E-ME', 'employee');

        $this->actingAsPerson($me)->get('/assign-director')
            ->assertOk()
            ->assertSee('この月の社員を確定')
            ->assertSee("fetch('/assignments/confirm-month'", false)
            ->assertSee('id="dirOfficeVal"', false);
    }
}
