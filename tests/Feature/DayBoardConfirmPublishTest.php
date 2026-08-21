<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 日別ボードの「✓ 確定にする」「📣 スタッフに公開」と、メンバーの「仮／確定」切替（2026-08-21 baba）。
 *
 * これまで＝この2つのボタンは画面の色が変わるだけで、DBには何も保存していなかった（見せかけ）。
 *   そのため「日別ボードで公開したつもり」なのにスタッフには何も出ない、という事故が起きる形だった。
 * これから＝確定は projects.status、公開はスタッフ公開ボードと同じ処理（staff_published）へ保存する。
 * あわせて、メンバーが「仮」か「確定」かを日別ボードに表示し、押して入れ替えられるようにした
 * （スタッフの画面に出るのは「確定」だけなので、ここで確定にしないと本人に見えない）。
 */
class DayBoardConfirmPublishTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
    }

    private function project(array $attrs = [])
    {
        return ProjectFactory::new()->create(array_merge([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(6)->format('Y-m-d'),
            'status' => '未着手',
            'staff_published' => false,
        ], $attrs));
    }

    /** 「✓ 確定にする」＝案件のアサイン状況が保存される（人数が足りなくても保存できる）。 */
    public function test_confirm_saves_project_status(): void
    {
        $p = $this->project(['required_count' => 10]);

        $this->actingAsPerson($this->emp())
            ->postJson('/projects/cells', ['id' => $p->id, 'status' => '確定'])
            ->assertOk();

        $this->assertSame('確定', $p->fresh()->status);
    }

    /** 下書き・キャンセルはこの口では受け付けない（案件登録・削除の流れで決まるものなので混ぜない）。 */
    public function test_draft_status_is_rejected(): void
    {
        $p = $this->project();

        $this->withoutExceptionHandling();
        try {
            $this->actingAsPerson($this->emp())
                ->postJson('/projects/cells', ['id' => $p->id, 'status' => '下書き']);
            $this->fail('下書きは弾かれるはず');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame('未着手', $p->fresh()->status);
    }

    /** 「📣 スタッフに公開」＝公開ボードと同じ処理で staff_published が立つ（人数不足でも公開できる）。 */
    public function test_publish_from_the_board_publishes(): void
    {
        $p = $this->project(['required_count' => 20]);

        $this->actingAsPerson($this->emp())
            ->postJson('/assign-publish/set', ['ids' => [$p->id], 'publish' => true, 'office' => '東京'])
            ->assertOk()
            ->assertJson(['ok' => true, 'updated' => 1]);

        $this->assertTrue((bool) $p->fresh()->staff_published);
    }

    /** メンバーの「仮 → 確定」。役割は消えず、確定した記録（confirmed_at）が残る。 */
    public function test_member_can_be_switched_from_tentative_to_confirmed(): void
    {
        $p = $this->project();
        $staff = PersonFactory::new()->staff()->create(['office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'MC', 'status' => '仮',
        ]);

        $this->actingAsPerson($this->emp())->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $staff->id, 'action' => 'assign', 'status' => '確定',
        ])->assertOk()->assertJson(['ok' => true, 'status' => '確定']);

        $row = Assignment::where('project_id', $p->id)->where('staff_id', $staff->id)->first();
        $this->assertSame('確定', $row->status);
        $this->assertSame('MC', $row->role, '役割は消えない');
        $this->assertNotNull($row->confirmed_at, '確定した記録が残る');
    }

    /** メンバーの「確定 → 仮」。確定の記録は消える（スタッフの画面からも消える状態に戻る）。 */
    public function test_member_can_be_switched_back_to_tentative(): void
    {
        $p = $this->project(['staff_published' => true]);
        $staff = PersonFactory::new()->staff()->create(['office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
            'confirmed_at' => Carbon::now()->subDay(),
        ]);

        $this->actingAsPerson($this->emp())->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $staff->id, 'action' => 'assign', 'status' => '仮',
        ])->assertOk();

        $row = Assignment::where('project_id', $p->id)->where('staff_id', $staff->id)->first();
        $this->assertSame('仮', $row->status);
        $this->assertNull($row->confirmed_at);
    }

    /** 画面に、仮／確定の切替と本物の公開処理が入っている（見せかけの文言は残っていない）。 */
    public function test_board_view_uses_the_real_endpoints(): void
    {
        $this->project();

        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('changeMemberStatus(', $html, 'メンバーの仮／確定の切替');
        $this->assertStringContainsString("fetch('/assign-publish/set'", $html, '公開は公開ボードと同じ入口');
        $this->assertStringNotContainsString('モックのため実際の通知は行いません', $html);
        $this->assertStringNotContainsString('スタッフに公開しました（モック）', $html);
    }
}
