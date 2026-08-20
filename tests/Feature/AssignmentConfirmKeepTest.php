<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「確定したアサインが壊れない」ことを守るテスト。
 *
 * アサインを書き込める画面が6つあり、以前は
 *   ・D決め画面で保存し直すと、確定済みのD・SD・FCが「仮」に落ちる
 *   ・ピックアップ画面で保存すると、その案件×その日のアサインが全部消える（Dが消える）
 *   ・誰がいつ確定したかが残らない
 * という状態だった。ここが崩れるとスタッフ画面の「確定アサイン」も一緒に壊れる。
 */
class AssignmentConfirmKeepTest extends TestCase
{
    use RefreshDatabase;

    private function date(int $plus = 3): string
    {
        return Carbon::today()->addDays($plus)->format('Y-m-d');
    }

    /** D決め画面の保存では、確定済みのDが「仮」に戻らない。 */
    public function test_director_save_keeps_confirmed_status(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $dir = PersonFactory::new()->create(['name' => 'D担当']);
        $project = ProjectFactory::new()->create(['start_date' => $this->date()]);

        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $dir->id,
            'date' => $this->date(), 'role' => 'D', 'status' => '確定',
        ]);

        // D決め画面から、同じDを選んだまま保存し直す（status は送らない）。
        $this->actingAsPerson($emp)
            ->post('/assign-director/save', ['dir' => [$project->id => $dir->id]])
            ->assertRedirect();

        $row = Assignment::where('project_id', $project->id)->where('staff_id', $dir->id)->first();
        $this->assertSame('確定', $row->status, 'D決め画面の保存で確定が仮に戻ってはいけない');
    }

    /** D決め画面で新しく決めた担当は「仮」から始まる。 */
    public function test_director_save_creates_new_row_as_tentative(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $dir = PersonFactory::new()->create(['name' => '新しいD']);
        $project = ProjectFactory::new()->create(['start_date' => $this->date()]);

        $this->actingAsPerson($emp)
            ->post('/assign-director/save', ['dir' => [$project->id => $dir->id]])
            ->assertRedirect();

        $row = Assignment::where('project_id', $project->id)->where('staff_id', $dir->id)->first();
        $this->assertSame('仮', $row->status);
    }

    /** ピックアップの保存で、D決めで入れた社員の行が消えない。 */
    public function test_pickup_save_keeps_employee_rows(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $dir = PersonFactory::new()->create(['name' => 'D担当']);          // 社員
        $staff = PersonFactory::new()->staff()->create(['name' => 'スタッフA']);
        $project = ProjectFactory::new()->create(['start_date' => $this->date()]);

        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $dir->id,
            'date' => $this->date(), 'role' => 'D', 'status' => '確定',
        ]);

        $this->actingAsPerson($emp)
            ->postJson('/pickup/save', [
                'project_id' => $project->id,
                'members' => [['staff_id' => $staff->id, 'role' => 'OP']],
            ])
            ->assertOk();

        $this->assertNotNull(
            Assignment::where('project_id', $project->id)->where('staff_id', $dir->id)->first(),
            'ピックアップの保存でDの行が消えてはいけない'
        );
        $this->assertNotNull(
            Assignment::where('project_id', $project->id)->where('staff_id', $staff->id)->first(),
            'ピックアップで選んだスタッフは保存される'
        );
    }

    /** 「仮 → 確定」にしたとき、誰が・いつ確定したかが残る。確定を仮に戻すと消える。 */
    public function test_confirm_records_who_and_when(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $staff = PersonFactory::new()->staff()->create();
        $project = ProjectFactory::new()->create(['start_date' => $this->date()]);

        // 仮で入れる
        $this->actingAsPerson($emp)->postJson('/entries/assign', [
            'project_id' => $project->id, 'staff_id' => $staff->id,
            'action' => 'assign', 'role' => 'OP', 'status' => '仮',
        ])->assertOk();

        $row = Assignment::where('project_id', $project->id)->where('staff_id', $staff->id)->first();
        $this->assertSame($emp->id, $row->assigned_by, '誰がアサインしたかが残る');
        $this->assertNull($row->confirmed_at, '仮のうちは確定の記録は付かない');

        // 確定に上げる
        $this->actingAsPerson($emp)->postJson('/entries/assign', [
            'project_id' => $project->id, 'staff_id' => $staff->id,
            'action' => 'assign', 'role' => 'OP', 'status' => '確定',
        ])->assertOk();

        $row->refresh();
        $this->assertSame('確定', $row->status);
        $this->assertSame($emp->id, $row->confirmed_by, '誰が確定したかが残る');
        $this->assertNotNull($row->confirmed_at, 'いつ確定したかが残る');

        // 仮に戻すと確定の記録は消える
        $this->actingAsPerson($emp)->postJson('/entries/assign', [
            'project_id' => $project->id, 'staff_id' => $staff->id,
            'action' => 'assign', 'role' => 'OP', 'status' => '仮',
        ])->assertOk();

        $row->refresh();
        $this->assertNull($row->confirmed_at);
        $this->assertNull($row->confirmed_by);
    }
}
