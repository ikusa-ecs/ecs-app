<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * エントリー一覧からの1人ぶんアサイン切替（App\Http\Controllers\AssignmentController@quickToggle）。
 * POST /entries/assign（tier:employee 配下）。テスト仕様書 IT-ASGN-03。
 *
 * 実装で確認した入力名・挙動：
 *   入力＝project_id / staff_id / action（'assign' or 'unassign'）/ role / status（'仮' or '確定'）。
 *   ・assign  … その案件×その人×本番日を assignments に1行追加（既存があれば更新）。JSON {ok, assigned:true}。
 *   ・unassign… その1行だけ削除（他の人には触れない）。JSON {ok, assigned:false}。
 *   対象日は案件の start_date。start_date が無い案件は 422 を返す。
 */
class EntryQuickToggleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * IT-ASGN-03：assign で1行追加、同じ人を unassign で削除、他の人のアサインは残る。
     */
    public function test_quick_toggle_assign_then_unassign_keeps_others(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $staffA   = PersonFactory::new()->staff()->create();
        $staffB   = PersonFactory::new()->staff()->create();

        // 別の人（B）は先にアサイン済み＝この行は最後まで残るはず。
        $resB = $this->actingAsPerson($employee)->postJson('/entries/assign', [
            'project_id' => $project->id,
            'staff_id'   => $staffB->id,
            'action'     => 'assign',
            'role'       => 'MC',
            'status'     => '確定',
        ]);
        $resB->assertOk()->assertJson(['ok' => true, 'assigned' => true]);

        // A を assign ＝1行追加される。
        $resAssign = $this->actingAsPerson($employee)->postJson('/entries/assign', [
            'project_id' => $project->id,
            'staff_id'   => $staffA->id,
            'action'     => 'assign',
            'role'       => 'OP',
            'status'     => '確定',
        ]);
        $resAssign->assertOk()->assertJson(['ok' => true, 'assigned' => true]);

        // A の1行が正しく入っている（案件×人×本番日）。
        $rowA = Assignment::where('project_id', $project->id)->where('staff_id', $staffA->id)->first();
        $this->assertNotNull($rowA);
        $this->assertSame('OP', $rowA->role);
        $this->assertSame('確定', $rowA->status);
        $this->assertSame('2026-09-01', $rowA->date->format('Y-m-d'));
        // この時点で全2行（A・B）。
        $this->assertSame(2, Assignment::where('project_id', $project->id)->count());

        // A を unassign ＝A の行だけ消える。
        $resUnassign = $this->actingAsPerson($employee)->postJson('/entries/assign', [
            'project_id' => $project->id,
            'staff_id'   => $staffA->id,
            'action'     => 'unassign',
        ]);
        $resUnassign->assertOk()->assertJson(['ok' => true, 'assigned' => false]);

        // A は消え、B は残る（他の人には触れない）。
        $this->assertSame(0, Assignment::where('project_id', $project->id)->where('staff_id', $staffA->id)->count());
        $this->assertSame(1, Assignment::where('project_id', $project->id)->where('staff_id', $staffB->id)->count());
        $this->assertSame(1, Assignment::where('project_id', $project->id)->count());
    }

    /**
     * IT-ASGN-03（補足）：同じ人を2回 assign しても行は増えず（1行のまま更新）、
     * 役割・状態が上書きされる（unique制約 案件×人×日 に沿う）。
     */
    public function test_assigning_same_person_twice_updates_single_row(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $staff    = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($employee)->postJson('/entries/assign', [
            'project_id' => $project->id,
            'staff_id'   => $staff->id,
            'action'     => 'assign',
            'role'       => 'OP',
            'status'     => '仮',
        ])->assertOk();

        $this->actingAsPerson($employee)->postJson('/entries/assign', [
            'project_id' => $project->id,
            'staff_id'   => $staff->id,
            'action'     => 'assign',
            'role'       => 'MC',
            'status'     => '確定',
        ])->assertOk();

        $this->assertSame(1, Assignment::where('project_id', $project->id)->count());
        $row = Assignment::where('project_id', $project->id)->where('staff_id', $staff->id)->first();
        $this->assertSame('MC', $row->role);
        $this->assertSame('確定', $row->status);
    }

    /**
     * IT-ASGN-03（補足）：開催日が未設定の案件では 422 を返し、行は増えない。
     */
    public function test_quick_toggle_requires_start_date(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => null]);
        $staff    = PersonFactory::new()->staff()->create();

        $res = $this->actingAsPerson($employee)->postJson('/entries/assign', [
            'project_id' => $project->id,
            'staff_id'   => $staff->id,
            'action'     => 'assign',
            'role'       => 'OP',
            'status'     => '確定',
        ]);

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, Assignment::count());
    }
}
