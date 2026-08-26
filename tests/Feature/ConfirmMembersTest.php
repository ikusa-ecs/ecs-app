<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use App\Support\AssignmentRole;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「この案件のメンバーを全員確定にする」（POST /projects/confirm-members）。2026-08-26 baba要望。
 *
 * ⚠ 「公開」と「その人が確定か」は別のもので、公開してもメンバーは自動で確定にならない。
 *   そのため「公開したのにスタッフの画面に出ない」（＝仮のままだった）が起きていた。
 *   日別ボードの「✓確定にする」「📣スタッフに公開」から、この入口で全員そろえる。
 */
class ConfirmMembersTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(): Project
    {
        return ProjectFactory::new()->create([
            'office' => '東京', 'status' => '調整中',
            'start_date' => now()->addDays(10)->format('Y-m-d'),
        ]);
    }

    private function assign(Project $p, string $staffId, string $status): Assignment
    {
        $person = PersonFactory::new()->create(['id' => $staffId, 'role' => 'staff', 'office' => '東京']);

        return Assignment::create([
            'project_id' => $p->id, 'staff_id' => $person->id,
            'date' => $p->start_date, 'role' => AssignmentRole::OP, 'status' => $status,
        ]);
    }

    /** 「仮」の人だけが確定になる。 */
    public function test_tentative_members_become_confirmed(): void
    {
        $me = $this->manager();
        $p = $this->project();
        $a = $this->assign($p, 'S-001', '仮');
        $b = $this->assign($p, 'S-002', '仮');

        $this->actingAsPerson($me)->post('/projects/confirm-members', ['project_id' => $p->id])
            ->assertOk()->assertJson(['ok' => true, 'confirmed' => 2]);

        $this->assertSame('確定', $a->fresh()->status);
        $this->assertSame('確定', $b->fresh()->status);
    }

    /** 確定にした記録（誰が・いつ）が残る。 */
    public function test_confirmed_stamp_is_recorded(): void
    {
        $me = $this->manager();
        $p = $this->project();
        $a = $this->assign($p, 'S-001', '仮');

        $this->actingAsPerson($me)->post('/projects/confirm-members', ['project_id' => $p->id])->assertOk();

        $this->assertNotNull($a->fresh()->confirmed_at);
        $this->assertSame('E-001', $a->fresh()->confirmed_by);
    }

    /** ⚠ キャンセルのアサインは確定にしない（断られた人を戻さない）。 */
    public function test_cancelled_assignment_is_not_confirmed(): void
    {
        $me = $this->manager();
        $p = $this->project();
        $a = $this->assign($p, 'S-001', 'キャンセル');

        $this->actingAsPerson($me)->post('/projects/confirm-members', ['project_id' => $p->id])
            ->assertOk()->assertJson(['confirmed' => 0]);

        $this->assertSame('キャンセル', $a->fresh()->status);
    }

    /** 他の拠点の案件は触れない（保存の入口では必ず拠点チェック）。 */
    public function test_other_office_project_is_denied(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-020', 'name' => '一般 社員', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $p = ProjectFactory::new()->create([
            'office' => '名古屋', 'status' => '調整中',
            'start_date' => now()->addDays(10)->format('Y-m-d'),
        ]);
        $a = $this->assign($p, 'S-001', '仮');

        $this->actingAsPerson($me)->post('/projects/confirm-members', ['project_id' => $p->id])
            ->assertStatus(403);

        $this->assertSame('仮', $a->fresh()->status);
    }
}
