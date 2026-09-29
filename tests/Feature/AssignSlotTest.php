<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectSlot;
use App\Support\AssignSlots;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表の「まだ人が決まっていない枠」（2026-09-28 baba要望）。
 *
 * 現場のアサイン表では、人を決める前に
 *   「ここはOP」「ここはIKUSAマスト」「ここは派遣でOK」
 * と枠だけ先に書いている。それをECSでもできるようにした。
 *
 * 【守りたいこと】
 *   ・**誰がアサインされているかの正本は assignments ひとつ**。枠に staff_id は持たせない。
 *   ・Dは必ず1番上に来る（現場のアサイン表がそうで、「1番のPがDか」でDの有無を見ている）。
 *   ・Dが決まっていない案件では、D枠だけ先に立てて「イベプラ待ち」にできる。
 *     それを **D決めの画面が拾う**（拾えないと、立てた意味がない）。
 */
class AssignSlotTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project()
    {
        return ProjectFactory::new()->create([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'scale' => '中型',
        ]);
    }

    private function sheet($p)
    {
        return $this->actingAsPerson($this->manager())
            ->get('/assign-sheet?month='.$p->start_date->format('Y-m'));
    }

    /** 書くと枠ができる。同じ行に2回書いても枠は1つのまま。 */
    public function test_枠を作って直せる(): void
    {
        $p = $this->project();
        $me = $this->manager();

        $r1 = $this->actingAsPerson($me)->postJson('/assign-sheet/slot', [
            'project_id' => $p->id, 'field' => 'role', 'value' => 'OP',
        ])->assertOk()->json();
        $this->assertNotNull($r1['slot_id']);

        $this->actingAsPerson($me)->postJson('/assign-sheet/slot', [
            'project_id' => $p->id, 'slot_id' => $r1['slot_id'],
            'field' => 'remark', 'value' => 'IKUSAマスト',
        ])->assertOk();

        $this->assertSame(1, ProjectSlot::where('project_id', $p->id)->count(), '枠は1つのまま');
        $slot = ProjectSlot::where('project_id', $p->id)->first();
        $this->assertSame('OP', $slot->role);
        $this->assertSame('IKUSAマスト', $slot->remark);
    }

    /** 中身が空になったら枠ごと消える（空っぽの行が残り続けないように）。 */
    public function test_空にすると枠は消える(): void
    {
        $p = $this->project();
        $me = $this->manager();

        $id = $this->actingAsPerson($me)->postJson('/assign-sheet/slot', [
            'project_id' => $p->id, 'field' => 'role', 'value' => 'OP',
        ])->json('slot_id');

        $res = $this->actingAsPerson($me)->postJson('/assign-sheet/slot', [
            'project_id' => $p->id, 'slot_id' => $id, 'field' => 'role', 'value' => '',
        ])->assertOk()->json();

        $this->assertNull($res['slot_id']);
        $this->assertSame(0, ProjectSlot::where('project_id', $p->id)->count());
    }

    /** 知らないポジションは受け付けない（画面から自由な文字が入らないように）。 */
    public function test_知らないポジションは弾く(): void
    {
        $p = $this->project();

        $this->actingAsPerson($this->manager())->postJson('/assign-sheet/slot', [
            'project_id' => $p->id, 'field' => 'role', 'value' => 'でたらめ',
        ])->assertStatus(422);

        $this->assertSame(0, ProjectSlot::where('project_id', $p->id)->count());
    }

    /** 「D枠を作る」＝役割Dの枠＋イベプラの印。2回押しても増えない。 */
    public function test_D枠を作る(): void
    {
        $p = $this->project();
        $me = $this->manager();

        $this->actingAsPerson($me)->postJson('/assign-sheet/director-slot', ['project_id' => $p->id])->assertOk();
        $this->actingAsPerson($me)->postJson('/assign-sheet/director-slot', ['project_id' => $p->id])->assertOk();

        $slots = ProjectSlot::where('project_id', $p->id)->get();
        $this->assertCount(1, $slots, '2回押しても増えない');
        $this->assertSame('D', $slots[0]->role);
        $this->assertSame(AssignSlots::EVENT_PLANNER, $slots[0]->placeholder);
    }

    /** Dの枠は、アサイン済みの人より上（1番）に来る。 */
    public function test_D枠は1番上に来る(): void
    {
        $p = $this->project();
        $me = $this->manager();
        $staff = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => 'オペ太郎']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
        ]);
        $this->actingAsPerson($me)->postJson('/assign-sheet/director-slot', ['project_id' => $p->id])->assertOk();

        $card = collect($this->sheet($p)->assertOk()->original->getData()['cards'])->firstWhere('id', $p->id);
        $lines = $card['blockLines'];

        $this->assertSame(1, $lines[0]['no']);
        $this->assertSame('slot', $lines[0]['kind'], '1番はD枠');
        $this->assertSame('D', $lines[0]['role']);
        $this->assertSame('member', $lines[1]['kind'], 'その下にアサイン済みのOP');
    }

    /** 「D枠を作る」ボタンが画面に出る＝見出しのすぐ下（1番の行より上）。以前はいちばん下で見つからなかった（2026-09-29）。 */
    public function test_D枠ボタンは見出しのすぐ下に出る(): void
    {
        $p = $this->project();

        $html = $this->sheet($p)->assertOk()->getContent();

        $btn = strpos($html, 'ecsSheetAddDirectorSlot(this)');
        $row1 = strpos($html, '<span class="c-no">1</span>');
        $this->assertNotFalse($btn, 'ボタンが出ている');
        $this->assertNotFalse($row1);
        $this->assertLessThan($row1, $btn, '1番の行より上');
    }

    /** Dに人が入っている案件には「D枠を作る」ボタンを出さない。 */
    public function test_Dが決まっていればボタンは出さない(): void
    {
        $p = $this->project();
        $emp = PersonFactory::new()->create(['office' => '東京', 'name' => 'ディレ子']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $emp->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'D', 'status' => '確定',
        ]);

        $card = collect($this->sheet($p)->assertOk()->original->getData()['cards'])->firstWhere('id', $p->id);

        $this->assertFalse($card['dUndecided']);
    }

    /** ⚠ 立てたD枠を、D決めの画面が「イベプラ待ち」として拾えること（拾えないと立てた意味がない）。 */
    public function test_D決めの画面がイベプラ待ちを拾う(): void
    {
        $p = $this->project();
        $me = $this->manager();
        $this->actingAsPerson($me)->postJson('/assign-sheet/director-slot', ['project_id' => $p->id])->assertOk();

        $cases = $this->actingAsPerson($me)->get('/assign-director')->assertOk()
            ->original->getData()['dirCases'] ?? null;
        if ($cases === null) {
            // 画面へ渡す名前が違う場合に備えて、渡っているデータから探す。
            $data = $this->actingAsPerson($me)->get('/assign-director')->assertOk()->original->getData();
            $cases = collect($data)->first(fn ($v) => is_iterable($v) && collect($v)->contains(fn ($x) => is_array($x) && array_key_exists('plannerWait', $x)));
        }

        $case = collect($cases)->firstWhere('id', $p->id);
        $this->assertNotNull($case, 'D決めの画面にこの案件が並んでいること');
        $this->assertTrue($case['plannerWait'], 'イベプラ待ちとして拾えること');
    }

    /** 他拠点の案件は、URLを直打ちしても枠を作れない（保存の入口で必ず止める）。 */
    public function test_他拠点の案件には作れない(): void
    {
        $other = ProjectFactory::new()->create([
            'office' => '大阪', 'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);
        $emp = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($emp)->postJson('/assign-sheet/slot', [
            'project_id' => $other->id, 'field' => 'role', 'value' => 'OP',
        ])->assertStatus(403);

        $this->assertSame(0, ProjectSlot::where('project_id', $other->id)->count());
    }
}
