<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Project;
use App\Support\BoardBulkDelete;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードの「🗑 まとめて削除」（2026-10-05 baba要望・一時的）。
 * ・Administratorだけ／共通設定のスイッチが入っているときだけ。
 * ・preview は消さずに件数だけ返す。本番の削除は子案件とアサインも一緒に消す（1件削除と同じ）。
 */
class BoardBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京', 'must_onboard' => false]);
    }

    public function test_スイッチが切れていると消せない(): void
    {
        $p = ProjectFactory::new()->create(['office' => '東京']);
        $this->actingAsPerson($this->admin())
            ->postJson('/projects/bulk-delete', ['ids' => [$p->id]])
            ->assertStatus(403);
        $this->assertNotNull(Project::find($p->id));
    }

    public function test_管理者でも消せない(): void
    {
        BoardBulkDelete::setEnabled(true);
        $p = ProjectFactory::new()->create(['office' => '東京']);
        $mgr = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($mgr)->postJson('/projects/bulk-delete', ['ids' => [$p->id]]);
        $this->assertNotNull(Project::find($p->id));
    }

    public function test_下見は消さずに件数を返し_本番は子案件とアサインも消す(): void
    {
        BoardBulkDelete::setEnabled(true);
        $date = Carbon::today()->addDays(3)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);
        $child = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'parent_project_id' => $p->id]);
        $keep = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);
        $s = PersonFactory::new()->create();
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s->id, 'date' => $date, 'role' => 'OP', 'status' => '仮']);
        Application::create(['project_id' => $p->id, 'staff_id' => $s->id, 'intent' => '希望']);
        $admin = $this->admin();

        $res = $this->actingAsPerson($admin)
            ->postJson('/projects/bulk-delete', ['ids' => [$p->id], 'preview' => true])
            ->assertOk()->json();
        $this->assertSame(1, $res['rows'][0]['members']);
        $this->assertSame(1, $res['rows'][0]['entries']);
        $this->assertSame(1, $res['rows'][0]['children']);
        $this->assertNotNull(Project::find($p->id), '下見では消さない');

        $this->actingAsPerson($admin)
            ->postJson('/projects/bulk-delete', ['ids' => [$p->id]])
            ->assertOk()->assertJson(['deleted' => 1, 'children' => 1]);
        $this->assertNull(Project::find($p->id));
        $this->assertNull(Project::find($child->id));
        $this->assertNotNull(Project::find($keep->id), '選んでいない案件は残る');
        $this->assertSame(0, Assignment::where('project_id', $p->id)->count());
        $this->assertSame(1, Application::where('project_id', $p->id)->count(), 'エントリーは1件削除と同じく残す');
    }

    public function test_日別ボードにはスイッチが入っていてAdministratorのときだけ出す(): void
    {
        $admin = $this->admin();
        $this->actingAsPerson($admin)->get('/assign')->assertOk()->assertSee('window.ECS_BULK_DELETE = false', false);
        BoardBulkDelete::setEnabled(true);
        $this->actingAsPerson($admin)->get('/assign')->assertOk()->assertSee('window.ECS_BULK_DELETE = true', false);
        $mgr = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($mgr)->get('/assign')->assertOk()->assertSee('window.ECS_BULK_DELETE = false', false);
    }
}
