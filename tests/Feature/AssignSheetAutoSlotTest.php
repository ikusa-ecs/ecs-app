<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Support\AssignSlots;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表：コンテンツ×規模で必要なポジションを空き枠として先に出す（2026-11-11 小沼さん）。
 * 保存しない枠。埋まった分（その役割の人・派遣・手で作った枠）は出さない。D・SDは出さない（D決めが正本）。
 */
class AssignSheetAutoSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_slots_are_need_minus_filled(): void
    {
        $need = ['D' => 1, 'MC' => 1, 'OP' => 2, 'FC' => 3];
        $members = [['roleCode' => 'OP', 'status' => '仮'], ['roleCode' => 'FC', 'status' => 'キャンセル'], ['roleCode' => 'D', 'status' => '確定']];
        $dispatches = [['role' => 'FC', 'count' => 2]];
        $slots = [['role' => 'MC']];

        $roles = array_column(AssignSlots::autoSlots($need, $members, $dispatches, $slots), 'role');
        $this->assertSame(['OP', 'FC'], $roles, 'MCは手の枠で埋まり・OPは1人いる・FCは派遣2名（キャンセルは数えない）・Dは出さない');
    }

    public function test_sheet_shows_auto_slot_and_fill_uses_role(): void
    {
        Content::create(['id' => 'CT-1', 'content_name' => '謎解き', 'active' => true]);
        ContentRoleRequirement::create(['content_id' => 'CT-1', 'scale' => '中型', 'position' => 'OP', 'count' => 1]);
        $d = Carbon::today()->startOfMonth()->addDays(5);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $d->toDateString(), 'status' => '未着手', 'content_ids' => ['CT-1'], 'scale' => '中型']);
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get('/assign-sheet?month='.$d->format('Y-m'))->assertOk()
            ->assertSee('data-role="OP"', false)->assertSee('auto-mark', false);

        // 名前を入れる＝OPで入る（画面は data-role を送る）→ 空き枠は消える
        $emp = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);
        $this->actingAsPerson($me)->postJson('/entries/assign', ['project_id' => $p->id, 'staff_id' => $emp->id, 'action' => 'assign', 'status' => '仮', 'role' => 'OP'])->assertOk();
        $this->assertSame('OP', Assignment::where('project_id', $p->id)->value('role'));
        $this->actingAsPerson($me)->get('/assign-sheet?month='.$d->format('Y-m'))->assertOk()
            ->assertDontSee('data-role="OP"', false);
    }
}
