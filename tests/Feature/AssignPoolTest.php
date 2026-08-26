<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Support\AssignmentRole;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「アサイン表に出す／出さない」（people.in_assign_pool）。2026-08-26 baba要望。
 *
 * 社員名簿には現場のアサインに入らない人（管理部門・営業だけの人など）も居るので、
 * アサインの画面から外せるようにした。
 *  ・外す＝社員の出勤可能日の一覧／D決め／D/SD・物品担当のプルダウンに出さない
 *  ・名簿・集計・営業担当のプルダウンには今までどおり出す
 *  ・すでに担当に入っている人は、外してもその画面には残す（保存で担当が外れないように）
 */
class AssignPoolTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $id = 'E-001'): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function employee(string $id, string $name, bool $inPool = true): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false, 'in_assign_pool' => $inPool,
        ]);
    }

    /** 既定は「出す」＝今までの動きが変わらない。 */
    public function test_default_is_in_pool(): void
    {
        $this->assertTrue((bool) $this->employee('E-010', '田中 健一')->in_assign_pool);
    }

    /** 社員の出勤可能日の一覧には、外した社員が出ない。 */
    public function test_excluded_employee_is_not_listed_in_availability(): void
    {
        $me = $this->manager();
        $this->employee('E-010', '出る 社員');
        $this->employee('E-011', '出さない 社員', false);

        $employees = collect($this->actingAsPerson($me)
            ->get('/employee-availability')->assertOk()->viewData('employees'));

        $this->assertNotNull($employees->firstWhere('id', 'E-010'));
        $this->assertNull($employees->firstWhere('id', 'E-011'));
    }

    /**
     * ⚠ 自分は外していても必ず一覧に残す。
     * 画面は「先頭の行＝自分」という決まりで動いているので、自分が消えると
     * 他人の行に自分の入力が出てしまう。
     */
    public function test_myself_stays_listed_even_when_excluded(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-002', 'name' => '外された担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false, 'in_assign_pool' => false,
        ]);

        $employees = collect($this->actingAsPerson($me)
            ->get('/employee-availability')->assertOk()->viewData('employees'));

        $this->assertSame('E-002', $employees->first()['id'], '自分が先頭に残ること');
    }

    /** D決めの社員一覧にも、外した社員が出ない。 */
    public function test_excluded_employee_is_not_listed_in_director_board(): void
    {
        $me = $this->manager();
        $this->employee('E-010', '出る 社員');
        $this->employee('E-011', '出さない 社員', false);

        $employees = collect($this->actingAsPerson($me)
            ->get('/assign-director')->assertOk()->viewData('employees'));

        $this->assertNotNull($employees->firstWhere('id', 'E-010'));
        $this->assertNull($employees->firstWhere('id', 'E-011'));
    }

    /**
     * ⚠ すでにD/SDに入っている人は、外してもD決めの一覧に残す。
     * 保存は「いま画面に出ている人で上書き」なので、候補から消えると担当が外れてしまう。
     */
    public function test_already_assigned_director_stays_listed(): void
    {
        $me = $this->manager();
        $d = $this->employee('E-011', '外されたD', false);
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'status' => '確定',
            'start_date' => now()->startOfMonth()->addDays(5)->format('Y-m-d'),
        ]);
        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $d->id,
            'date' => $project->start_date, 'role' => AssignmentRole::D, 'status' => '確定',
        ]);

        $employees = collect($this->actingAsPerson($me)
            ->get('/assign-director')->assertOk()->viewData('employees'));

        $this->assertNotNull($employees->firstWhere('id', 'E-011'),
            'すでにDに入っている人は外しても残ること');
    }

    /** 案件一覧のD/SD・物品担当のプルダウンにも、外した社員が出ない。 */
    public function test_excluded_employee_is_not_in_director_pulldown(): void
    {
        $me = $this->manager();
        $this->employee('E-010', '出る 社員');
        $this->employee('E-011', '出さない 社員', false);

        $employees = collect($this->actingAsPerson($me)
            ->get('/projects')->assertOk()->viewData('employees'));

        $this->assertNotNull($employees->firstWhere('id', 'E-010'));
        $this->assertNull($employees->firstWhere('id', 'E-011'));
    }

    /** 名簿には今までどおり全員出る（そこで切り替えるので隠してはいけない）。 */
    public function test_roster_still_lists_excluded_employee(): void
    {
        $me = $this->manager();
        $this->employee('E-011', '出さない 社員', false);

        $employees = collect($this->actingAsPerson($me)
            ->get('/employees')->assertOk()->viewData('employees'));

        $row = $employees->firstWhere('id', 'E-011');
        $this->assertNotNull($row, '名簿には出ること');
        $this->assertFalse($row['inAssignPool'], '外してあることが画面に伝わること');
    }

    /** 管理者以上は名簿から切り替えられる。 */
    public function test_manager_can_toggle_from_roster(): void
    {
        $me = $this->manager();
        $this->employee('E-010', '田中 健一');

        $this->actingAsPerson($me)
            ->post('/employees/E-010/profile', ['in_assign_pool' => '0'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertFalse((bool) Person::find('E-010')->in_assign_pool);
    }

    /** 一般社員は切り替えられない（アサイン担当の操作なので管理者以上）。 */
    public function test_employee_cannot_toggle(): void
    {
        $me = $this->employee('E-020', '一般 社員');
        $this->employee('E-010', '田中 健一');

        $this->actingAsPerson($me)
            ->post('/employees/E-010/profile', ['in_assign_pool' => '0'])
            ->assertStatus(403);

        $this->assertTrue((bool) Person::find('E-010')->in_assign_pool);
    }
}
