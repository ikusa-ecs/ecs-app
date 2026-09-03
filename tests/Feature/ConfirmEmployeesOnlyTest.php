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
 * 日別ボードの「社員◯名を確定」ボタン（2026-09-03 baba要望
 * 「日別ボードで社員をまとめて確定にするボタンが欲しい」）。
 *
 * ⚠ 社員はスタッフ画面への公開に関係ないので、先に確定にしてしまいたい、が背景。
 *   一人ずつ名前の横の「仮」を押すのが手間だった。
 * ⚠ スタッフは触らないこと。まとめて確定にすると、まだ声を掛けていない人が
 *   本人の画面に出てしまい、公開の段取りが崩れる。
 * ⚠ 入口は今までの /projects/confirm-members 1つ（only=employee）。
 *   確定のやり方を2つ作らない（片方だけ直して食い違う）。
 */
class ConfirmEmployeesOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::today()->addDays(5);

        ProjectFactory::new()->create([
            'id' => 'P-CE', 'project_name' => 'テスト案件', 'office' => '東京',
            'start_date' => $this->day->format('Y-m-d'), 'status' => '調整中', 'required_count' => 5,
        ]);
    }

    private function person(string $id, string $role): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'role' => $role, 'permission' => $role === 'staff' ? 'staff' : 'manager',
            'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
    }

    private function assign(string $staffId, string $status = '仮'): Assignment
    {
        return Assignment::create([
            'project_id' => 'P-CE', 'staff_id' => $staffId, 'role' => 'FC',
            'date' => $this->day->format('Y-m-d'), 'status' => $status,
        ]);
    }

    /** 社員だけが確定になり、スタッフは仮のまま残ること。 */
    public function test_only_employees_are_confirmed(): void
    {
        $me = $this->person('E-ME', 'employee');
        $this->person('E-A', 'employee');
        $this->person('S-A', 'staff');
        $emp = $this->assign('E-A');
        $staff = $this->assign('S-A');

        $this->actingAsPerson($me)
            ->postJson('/projects/confirm-members', ['project_id' => 'P-CE', 'only' => 'employee'])
            ->assertOk()
            ->assertJson(['ok' => true, 'confirmed' => 1]);

        $this->assertSame('確定', $emp->refresh()->status);
        $this->assertSame('仮', $staff->refresh()->status, 'スタッフまで確定になっています（公開の段取りが崩れます）。');
    }

    /** only を付けなければ、今までどおり全員が確定になること（公開の動きを変えない）。 */
    public function test_without_only_everyone_is_confirmed(): void
    {
        $me = $this->person('E-ME', 'employee');
        $this->person('E-A', 'employee');
        $this->person('S-A', 'staff');
        $emp = $this->assign('E-A');
        $staff = $this->assign('S-A');

        $this->actingAsPerson($me)
            ->postJson('/projects/confirm-members', ['project_id' => 'P-CE'])
            ->assertOk()
            ->assertJson(['confirmed' => 2]);

        $this->assertSame('確定', $emp->refresh()->status);
        $this->assertSame('確定', $staff->refresh()->status);
    }

    /**
     * 名簿に無いIDは巻き込まないこと。
     * ⚠ 「社員だけ」のはずが、正体の分からない行まで確定になると気づけない。
     */
    public function test_unknown_ids_are_left_alone(): void
    {
        $me = $this->person('E-ME', 'employee');
        $ghost = $this->assign('X-NOBODY');

        $this->actingAsPerson($me)
            ->postJson('/projects/confirm-members', ['project_id' => 'P-CE', 'only' => 'employee'])
            ->assertOk()
            ->assertJson(['confirmed' => 0]);

        $this->assertSame('仮', $ghost->refresh()->status);
    }

    /** 画面にボタンと仕掛けがあること（消えたら押せない）。 */
    public function test_the_button_is_on_the_screen(): void
    {
        $me = $this->person('E-ME', 'employee');

        $this->actingAsPerson($me)->get('/assign')
            ->assertOk()
            ->assertSee('function fixEmployees', false)
            ->assertSee("only: 'employee'", false)
            ->assertSee('社員${kariEmpN}名を確定', false);
    }
}
