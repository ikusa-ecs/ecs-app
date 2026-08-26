<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use App\Support\AssignmentRole;
use App\Support\EventCount;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件の「キャンセル」（projects.is_cancelled）。2026-08-26 baba要望。
 *
 * これまでは中止になった案件を「アーカイブ（隠す）」で片づけていたが、
 * ⚠ アーカイブは**案件一覧とスタッフ画面しか見ていない**ため、アサイン表・D決め・
 *   公開ボード・日別ボードには**出たまま**だった（babaが実際に困った）。
 *
 * キャンセルにすると：
 *  ・案件一覧の実施形態の欄が「キャンセル」になる（実施形態の値は消さない）
 *  ・イベント数に数えない
 *  ・これからの仕事を並べる画面（アサイン系・スタッフ画面）から外れる
 *  ・記録は消えない（削除とは別）
 */
class ProjectCancelTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(array $attrs = []): Project
    {
        return ProjectFactory::new()->create(array_merge([
            'office' => '東京', 'status' => '確定', 'staff_published' => true,
            'is_recruiting' => true, 'format' => 'リアル',
            'start_date' => now()->addDays(10)->format('Y-m-d'),
        ], $attrs));
    }

    /** 既定はキャンセルではない（今までの動きが変わらない）。 */
    public function test_default_is_not_cancelled(): void
    {
        $this->assertFalse((bool) $this->project()->is_cancelled);
    }

    /** 画面から切り替えられる（管理者）。 */
    public function test_manager_can_cancel_and_restore(): void
    {
        $me = $this->manager();
        $p = $this->project();

        $this->actingAsPerson($me)->post('/projects/cancel', ['id' => $p->id, 'cancelled' => true])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue((bool) $p->fresh()->is_cancelled);

        $this->actingAsPerson($me)->post('/projects/cancel', ['id' => $p->id, 'cancelled' => false])
            ->assertOk();
        $this->assertFalse((bool) $p->fresh()->is_cancelled);
    }

    /** ⚠ 実施形態・状態・アサインは消さない（記録なので残す）。 */
    public function test_cancel_keeps_the_record(): void
    {
        $me = $this->manager();
        $staff = PersonFactory::new()->create(['id' => 'S-001', 'role' => 'staff', 'office' => '東京']);
        $p = $this->project();
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date, 'role' => AssignmentRole::OP, 'status' => '確定',
        ]);

        $this->actingAsPerson($me)->post('/projects/cancel', ['id' => $p->id, 'cancelled' => true])->assertOk();

        $p->refresh();
        $this->assertSame('リアル', $p->format, '実施形態は消さない（戻せるように）');
        $this->assertSame('確定', $p->status, '状態も消さない');
        $this->assertSame(1, Assignment::where('project_id', $p->id)->count(), 'アサインも残す');
    }

    /** イベント数には数えない。 */
    public function test_cancelled_project_is_not_counted_as_event(): void
    {
        $p = $this->project(['is_cancelled' => true]);

        $this->assertFalse(EventCount::counts($p));
        $this->assertSame('キャンセルのため', EventCount::autoReason($p));
    }

    /** 案件一覧には出す（記録なので隠さない）。画面側のチェックで非表示にする。 */
    public function test_project_list_still_receives_cancelled_project(): void
    {
        $me = $this->manager();
        $p = $this->project(['is_cancelled' => true]);

        $cases = collect($this->actingAsPerson($me)->get('/projects')->assertOk()->viewData('cases'));
        $row = $cases->firstWhere('id', $p->id);

        $this->assertNotNull($row, '一覧のデータには含める');
        $this->assertTrue($row['cancelled'], '画面が「キャンセル」と出せること');
    }

    /** これからの仕事を並べる画面からは外れる。 */
    public function test_cancelled_project_disappears_from_work_screens(): void
    {
        $me = $this->manager();
        $live = $this->project();
        $dead = $this->project(['is_cancelled' => true]);

        foreach (['/assign-publish' => 'cases', '/assign-director' => 'cases', '/assign-sheet' => 'cards'] as $url => $key) {
            $rows = collect($this->actingAsPerson($me)->get($url)->assertOk()->viewData($key));
            $ids = $rows->map(fn ($r) => is_array($r) ? ($r['id'] ?? null) : $r->id)->all();
            $this->assertContains($live->id, $ids, "{$url} には実施する案件が出ること");
            $this->assertNotContains($dead->id, $ids, "{$url} からキャンセルが消えること");
        }
    }

    /** スタッフ本人の確定アサインからも消える（行かなくてよい現場を見せない）。 */
    public function test_cancelled_project_disappears_from_staff_portal(): void
    {
        $staff = PersonFactory::new()->create([
            'id' => 'S-001', 'name' => '山田 涼', 'role' => 'staff',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $p = $this->project(['is_cancelled' => true]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date, 'role' => AssignmentRole::OP, 'status' => '確定',
        ]);

        $mine = collect($this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('published'));

        $this->assertSame(0, $mine->count(), 'キャンセルの現場は本人にも出さない');
    }
}
