<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentRoleRequirement;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードにも、アサイン表と同じ「必要ポジションの空き枠」を出す（2026-11-12 小沼さん）。
 * 判定の正本＝AssignSlots::autoSlots（カードを作るときも、メンバーを足したあとの取り直し /assign/auto-slots も同じ）。
 * 空き枠から入れた人は、その役割で assignments に入る（画面は role を送る）。
 */
class BoardAutoSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_card_has_auto_slots_and_fill_uses_role(): void
    {
        Content::create(['id' => 'CT-1', 'content_name' => '謎解き', 'active' => true]);
        foreach (['D' => 1, 'OP' => 1, 'FC' => 2] as $pos => $n) {
            ContentRoleRequirement::create(['content_id' => 'CT-1', 'scale' => '中型', 'position' => $pos, 'count' => $n]);
        }
        $d = Carbon::today()->addDays(3);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $d->toDateString(), 'status' => '未着手',
            'content_ids' => ['CT-1'], 'scale' => '中型', 'required_count' => 10]);
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);

        $card = collect($this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases'])
            ->firstWhere('id', $p->id);
        $this->assertNotNull($card);
        $this->assertSame(['OP', 'FC', 'FC'], $card['autoSlots'], 'D は出さない・必要ポジションが空きで並ぶ');

        // 空き枠（OP）から入れる＝OP で入る → 取り直すと OP の空きが消える
        $emp = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);
        $this->actingAsPerson($me)->postJson('/entries/assign', ['project_id' => $p->id, 'staff_id' => $emp->id, 'action' => 'assign', 'status' => '仮', 'role' => 'OP'])->assertOk();
        $this->assertSame('OP', Assignment::where('project_id', $p->id)->value('role'));

        $this->actingAsPerson($me)->getJson('/assign/auto-slots?id='.urlencode($p->id))->assertOk()
            ->assertJson(['ok' => true, 'roles' => ['FC', 'FC']]);

        // 運営人数を超えて空きを出さない（アサイン表と同じ）：運営2名・1人入っている → 空きは1つ
        $p->update(['required_count' => 2]);
        $this->actingAsPerson($me)->getJson('/assign/auto-slots?id='.urlencode($p->id))->assertOk()
            ->assertJson(['roles' => ['FC']]);
        $card = collect($this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases'])
            ->firstWhere('id', $p->id);
        $this->assertSame(['FC'], $card['autoSlots'], 'カードと取り直しで同じ答え');

        // 画面のJSが空き枠を受け取って並べる（詰め替え漏れの見張り）
        $html = $this->actingAsPerson($me)->get('/assign')->assertOk()->getContent();
        $this->assertStringContainsString('autoSlots:(c.autoSlots || []).slice()', $html);
        $this->assertStringContainsString('autoSlotRowsHtml(c, editMode)', $html);
    }

    public function test_auto_slots_endpoint_404_for_unknown_project(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($me)->getJson('/assign/auto-slots?id=nope')->assertNotFound();
    }
}
