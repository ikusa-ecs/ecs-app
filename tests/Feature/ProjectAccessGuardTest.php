<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「保存処理の拠点チェック」のテスト。
 *
 * 画面の一覧は拠点で絞れていたが、保存処理は案件の持ち主を見ていなかったため、
 * URL やリクエストに他拠点の案件IDを直接書けば一般社員でも書き換えられた。
 * ルールは App\Support\ProjectAccess の1か所（管理者以上＝全拠点／社員＝自拠点＋共有）。
 */
class ProjectAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    private function soon(int $plus = 3): string
    {
        return Carbon::today()->addDays($plus)->format('Y-m-d');
    }

    /** 一般社員は他拠点の案件のセル（アサイン表）を書き換えられない。 */
    public function test_employee_cannot_edit_other_office_project_cell(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $osakaJob = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);

        $this->actingAsPerson($tokyo)
            ->postJson('/assign-sheet/project', [
                'project_id' => $osakaJob->id, 'field' => 'required_count', 'value' => '99',
            ])
            ->assertStatus(403);

        $this->assertNotSame(99, Project::find($osakaJob->id)->required_count);
    }

    /** 管理者は他拠点の案件も書き換えられる（応援・巻き取りのため）。 */
    public function test_manager_can_edit_other_office_project_cell(): void
    {
        $manager = PersonFactory::new()->create(['office' => '東京', 'permission' => 'manager']);
        $osakaJob = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);

        $this->actingAsPerson($manager)
            ->postJson('/assign-sheet/project', [
                'project_id' => $osakaJob->id, 'field' => 'required_count', 'value' => '99',
            ])
            ->assertOk();

        $this->assertSame(99, Project::find($osakaJob->id)->required_count);
    }

    /** 自分の拠点へ共有（ヘルプ）された他拠点の案件は書き換えられる。 */
    public function test_shared_project_is_editable(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $osakaJob = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);
        ProjectShare::create(['project_id' => $osakaJob->id, 'office' => '東京', 'kind' => 'ヘルプ']);

        $this->actingAsPerson($tokyo)
            ->postJson('/assign-sheet/project', [
                'project_id' => $osakaJob->id, 'field' => 'required_count', 'value' => '7',
            ])
            ->assertOk();

        $this->assertSame(7, Project::find($osakaJob->id)->required_count);
    }

    /** 一般社員は他拠点の案件にアサイン（エントリー切替）できない。 */
    public function test_employee_cannot_assign_to_other_office_project(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $staff = PersonFactory::new()->staff()->create(['office' => '大阪']);
        $osakaJob = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);

        $this->actingAsPerson($tokyo)
            ->postJson('/entries/assign', [
                'project_id' => $osakaJob->id, 'staff_id' => $staff->id,
                'action' => 'assign', 'role' => 'OP',
            ])
            ->assertStatus(403);

        $this->assertSame(0, Assignment::where('project_id', $osakaJob->id)->count());
    }

    /** 一般社員は他拠点の案件を公開できない。 */
    public function test_employee_cannot_publish_other_office_project(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $osakaJob = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);

        $this->actingAsPerson($tokyo)
            ->postJson('/assign-publish/set', ['ids' => [$osakaJob->id], 'publish' => true])
            ->assertOk()
            ->assertJson(['updated' => 0]);

        $this->assertFalse((bool) Project::find($osakaJob->id)->staff_published);
    }

    /** 一般社員は他拠点の案件でピックアップ保存できない。 */
    public function test_employee_cannot_pickup_save_other_office_project(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $staff = PersonFactory::new()->staff()->create(['office' => '大阪']);
        $osakaJob = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);

        $this->actingAsPerson($tokyo)
            ->postJson('/pickup/save', [
                'project_id' => $osakaJob->id,
                'members' => [['staff_id' => $staff->id, 'role' => 'OP']],
            ])
            ->assertStatus(403);

        $this->assertSame(0, Assignment::where('project_id', $osakaJob->id)->count());
    }

    /** D決め画面（複数まとめて保存）は、他拠点の案件だけを外して残りを保存する。 */
    public function test_director_save_skips_other_office_projects(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $dir = PersonFactory::new()->create(['office' => '東京', 'name' => 'D担当']);
        $mine = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        $theirs = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon(4)]);

        $this->actingAsPerson($tokyo)
            ->post('/assign-director/save', [
                'dir' => [$mine->id => $dir->id, $theirs->id => $dir->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, Assignment::where('project_id', $mine->id)->count(), '自拠点は保存される');
        $this->assertSame(0, Assignment::where('project_id', $theirs->id)->count(), '他拠点は保存しない');
    }

    /** 公開ON/OFFが案件の編集履歴に残る（以前は一括更新のため残らなかった）。 */
    public function test_publish_toggle_is_recorded_in_history(): void
    {
        $manager = PersonFactory::new()->create(['office' => '東京', 'permission' => 'manager']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);

        $this->actingAsPerson($manager)
            ->postJson('/assign-publish/set', ['ids' => [$p->id], 'publish' => true])
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('project_histories', [
            'project_id' => $p->id,
            'field'      => 'staff_published',
        ]);
    }

    /** 同じ状態にする操作では履歴を汚さない。 */
    public function test_publish_toggle_no_history_when_unchanged(): void
    {
        $manager = PersonFactory::new()->create(['office' => '東京', 'permission' => 'manager']);
        $p = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon()]);

        $this->actingAsPerson($manager)
            ->postJson('/assign-publish/set', ['ids' => [$p->id], 'publish' => true])
            ->assertOk()
            ->assertJson(['updated' => 0]);

        $this->assertDatabaseMissing('project_histories', [
            'project_id' => $p->id,
            'field'      => 'staff_published',
        ]);
    }
}
