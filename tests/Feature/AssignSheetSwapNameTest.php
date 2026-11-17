<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表：名前を入れたあとでも、編集モードで入れ替え／外すができる（2026-10-05 baba要望）。
 *
 * ・名前の行に「入れ替え／外す」の選ぶ欄が出る（編集モードのときだけ見える＝CSS）。
 * ・Dの行にも出す（2026-11-17 小沼さん）。以前は「ここで外すと案件側の写しから元のDが戻る」ので出していなかったが、
 *   2026-11-12 から写しがアサインを追いかける（DirectorSync::queueRefresh）ので、止める理由が無くなった。
 * ・⚠ アサインの行が無く、案件側の写しだけで補ったDの行には出さない（外す相手のアサインが無い）。
 * ・保存は今までどおり /entries/assign（入れる→外すの2回）。
 */
class AssignSheetSwapNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_名前の行に入れ替え欄が出てDの行にも出る(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $manager = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '中型']);
        $emp = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '入替テスト社員']);
        $dir = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'Dテスト社員']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $emp->id, 'date' => $date, 'role' => 'OP', 'status' => '仮']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $dir->id, 'date' => $date, 'role' => 'D', 'status' => '確定']);

        $html = $this->actingAsPerson($manager)
            ->get('/assign-sheet?month='.Carbon::parse($date)->format('Y-m'))
            ->assertOk()->getContent();

        // 入れ替え欄は OP と D の2人ぶん。
        $this->assertSame(2, substr_count($html, 'class="m-swapname"'));
        $this->assertStringContainsString('ecsSheetSwapMember', $html);
    }

    public function test_アサインの行が無く写しだけのDの行には入れ替え欄を出さない(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $manager = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $dir = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '写しだけD社員']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '中型', 'director_id' => $dir->id]);

        $html = $this->actingAsPerson($manager)
            ->get('/assign-sheet?month='.Carbon::parse($date)->format('Y-m'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('写しだけD社員', $html);
        $this->assertSame(0, substr_count($html, 'class="m-swapname"'));
    }

    public function test_アサイン表と同じ保存でDを入れ替えると案件側の写しも新しいDになる(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $manager = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $old = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '前のD社員']);
        $new = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '次のD社員']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '中型', 'director_id' => $old->id]);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $old->id, 'date' => $date, 'role' => 'D', 'status' => '確定']);

        // 画面の入れ替えと同じ順番＝新しい人を入れる → 前の人を外す。
        $this->actingAsPerson($manager)
            ->postJson('/entries/assign', ['project_id' => $p->id, 'staff_id' => $new->id, 'action' => 'assign', 'status' => '仮', 'role' => 'D'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->actingAsPerson($manager)
            ->postJson('/entries/assign', ['project_id' => $p->id, 'staff_id' => $old->id, 'action' => 'unassign'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame([$new->id], Assignment::where('project_id', $p->id)->where('role', 'D')->pluck('staff_id')->all());
        $this->assertSame($new->id, Project::find($p->id)->director_id);

        // 外すだけ → 写しも空になる（元のDが戻ってこない）。
        $this->actingAsPerson($manager)
            ->postJson('/entries/assign', ['project_id' => $p->id, 'staff_id' => $new->id, 'action' => 'unassign'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertNull(Project::find($p->id)->director_id);
    }
}
