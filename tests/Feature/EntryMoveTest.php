<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProjectHistory;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * エントリーの付け替え（/entry-move・2026-11-09 小沼さん）。
 *   ・管理者以上だけ。社員は403。
 *   ・選んだ人のエントリーだけ移す。希望／可・エントリーした日時はそのまま。
 *   ・移す先にすでにエントリーがある人は移さない。
 *   ・両方の案件の編集履歴に残す。
 */
class EntryMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_only_selected_entries_and_keeps_time(): void
    {
        $mgr = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'manager', 'office' => '東京']);
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京']);
        $a = PersonFactory::new()->staff()->create(['name' => '移す一郎']);
        $b = PersonFactory::new()->staff()->create(['name' => '残す二郎']);
        $c = PersonFactory::new()->staff()->create(['name' => '両方三郎']);
        $from = ProjectFactory::new()->create(['project_name' => '捜査会議']);
        $to = ProjectFactory::new()->create(['project_name' => '電脳謎パ']);

        $mk = function ($p, $pid, $intent, $at) {
            $app = new Application(['staff_id' => $p->id, 'project_id' => $pid, 'intent' => $intent]);
            $app->created_at = $at;
            $app->updated_at = $at;
            $app->save();
        };
        $mk($a, $from->id, '可', '2026-10-26 15:59:00');
        $mk($b, $from->id, '希望', '2026-10-30 19:10:00');
        $mk($c, $from->id, '希望', '2026-10-27 10:00:00');
        $mk($c, $to->id, '希望', '2026-11-09 16:00:00');

        $this->actingAsPerson($emp)->get('/entry-move')->assertForbidden();

        $this->actingAsPerson($mgr)->get('/entry-move?from='.$from->id.'&to='.$to->id)->assertOk()
            ->assertSee('移す一郎')->assertSee('残す二郎')->assertSee('移す先にもうエントリーがあります');

        $this->actingAsPerson($mgr)->post('/entry-move', [
            'from' => $from->id, 'to' => $to->id, 'staff' => [$a->id, $c->id],
        ])->assertRedirect()->assertSessionHas('status');

        $moved = Application::where('staff_id', $a->id)->first();
        $this->assertSame($to->id, $moved->project_id);
        $this->assertSame('可', $moved->intent);
        $this->assertSame('2026-10-26 15:59', $moved->created_at->format('Y-m-d H:i'));
        // 選ばなかった人・移す先にもうある人は元のまま
        $this->assertSame($from->id, Application::where('staff_id', $b->id)->value('project_id'));
        $this->assertSame(2, Application::where('staff_id', $c->id)->count());
        $this->assertTrue(Application::where('staff_id', $c->id)->where('project_id', $from->id)->exists());

        $this->assertTrue(ProjectHistory::where('project_id', $from->id)->where('field_label', 'エントリーを移した')->where('old_value', '移す一郎')->exists());
        $this->assertTrue(ProjectHistory::where('project_id', $to->id)->where('field_label', 'エントリーを移した')->where('new_value', '移す一郎')->exists());
    }

    public function test_same_project_is_rejected(): void
    {
        $mgr = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'manager', 'office' => '東京']);
        $p = ProjectFactory::new()->create();
        $s = PersonFactory::new()->staff()->create();
        Application::create(['staff_id' => $s->id, 'project_id' => $p->id, 'intent' => '希望']);

        $this->actingAsPerson($mgr)->post('/entry-move', ['from' => $p->id, 'to' => $p->id, 'staff' => [$s->id]])
            ->assertSessionHasErrors('to');
        $this->assertSame($p->id, Application::where('staff_id', $s->id)->value('project_id'));
    }
}
