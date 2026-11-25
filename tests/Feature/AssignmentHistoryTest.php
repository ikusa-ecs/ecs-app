<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectHistory;
use App\Support\AssignmentHistory;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサインの変更が、案件の編集履歴に残ること（2026-11-25 小沼さん）。
 * 正本＝App\Support\AssignmentHistory／書くタイミング＝App\Http\Middleware\RecordAssignmentHistory。
 */
class AssignmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function rows(string $projectId)
    {
        return ProjectHistory::where('project_id', $projectId)
            ->where('field', AssignmentHistory::FIELD)->orderBy('id')->get();
    }

    /** 画面から保存すると「だれを入れたか」が、変えた人の名前つきで残る。 */
    public function test_saving_records_who_was_put_in(): void
    {
        $employee = PersonFactory::new()->create(['name' => '社員 花子']);
        $project  = ProjectFactory::new()->create(['start_date' => '2026-12-01']);
        $staff    = PersonFactory::new()->staff()->create(['name' => '山田 太郎']);

        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '仮',
            'staff_ids'  => [$staff->id],
            'role'       => [$staff->id => 'MC'],
        ]);

        $rows = $this->rows($project->id);
        $this->assertCount(1, $rows);
        $this->assertSame('アサイン：入れた', $rows[0]->field_label);
        $this->assertSame('（なし）', $rows[0]->old_value);
        $this->assertStringContainsString('山田', $rows[0]->new_value);
        $this->assertStringContainsString('MC・仮', $rows[0]->new_value);
        $this->assertStringContainsString('仮', $rows[0]->new_value);
        $this->assertSame('社員 花子', $rows[0]->person_name);
    }

    /** 保存し直したとき、変わっていない人は残さない。外した人・仮→確定だけ残る。 */
    public function test_resaving_records_only_real_changes(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-12-01']);
        $stay     = PersonFactory::new()->staff()->create(['name' => '残る 人']);
        $leave    = PersonFactory::new()->staff()->create(['name' => '外れる 人']);
        foreach ([$stay, $leave] as $p) {
            // ⚠ 本番（MySQL）と同じ「日付のみ」の形で入れる（AssignmentSaveTest と同じ理由）。
            \DB::table('assignments')->insert([
                'project_id' => $project->id, 'staff_id' => $p->id, 'date' => '2026-12-01',
                'role' => 'FC', 'status' => '仮', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // 同じ顔ぶれ・同じ役割・同じ状態で保存し直す＝何も残らない。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id, 'status' => '仮',
            'staff_ids' => [$stay->id, $leave->id],
            'role' => [$stay->id => 'FC', $leave->id => 'FC'],
        ]);
        $this->assertCount(0, $this->rows($project->id));

        // 1人外して、確定にする。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id, 'status' => '確定',
            'staff_ids' => [$stay->id],
            'role' => [$stay->id => 'FC'],
        ]);
        $rows = $this->rows($project->id);
        $labels = $rows->pluck('field_label')->sort()->values()->all();
        $this->assertSame(['アサイン：変更', 'アサイン：外した'], $labels);
        $changed = $rows->firstWhere('field_label', 'アサイン：変更');
        $this->assertStringContainsString('仮', $changed->old_value);
        $this->assertStringContainsString('確定', $changed->new_value);
        $removed = $rows->firstWhere('field_label', 'アサイン：外した');
        $this->assertStringContainsString('外れる', $removed->old_value);
        $this->assertSame(1, Assignment::where('project_id', $project->id)->count());
    }

    /** 履歴の画面は「案件の情報」と「アサイン」でページが分かれる。 */
    public function test_history_screen_is_split_into_two_pages(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-12-01', 'start_time' => '12:00']);
        $staff    = PersonFactory::new()->staff()->create(['name' => '山田 太郎']);

        $this->actingAsPerson($employee);
        $project->update(['start_time' => '09:35']);
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id, 'status' => '仮',
            'staff_ids' => [$staff->id], 'role' => [$staff->id => 'MC'],
        ]);

        $this->actingAsPerson($employee)->get('/project-history?period=all')
            ->assertOk()
            ->assertSee('09:35')
            ->assertDontSee('アサイン：入れた');

        $this->actingAsPerson($employee)->get('/project-history?period=all&kind=assign')
            ->assertOk()
            ->assertSee('アサイン：入れた')
            ->assertSee('MC・仮')
            ->assertDontSee('09:35');
    }
}
