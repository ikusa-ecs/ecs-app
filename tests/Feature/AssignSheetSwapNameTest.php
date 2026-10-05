<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表：名前を入れたあとでも、編集モードで入れ替え／外すができる（2026-10-05 baba要望）。
 *
 * ・名前の行に「入れ替え／外す」の選ぶ欄が出る（編集モードのときだけ見える＝CSS）。
 * ・⚠ Dの行には出さない＝DはD決めの画面が正本（ここで外すと案件側の写しから元のDが戻る）。
 * ・保存は今までどおり /entries/assign（入れる→外すの2回）。押して動くかは画面で確かめる（JSは実行できない）。
 */
class AssignSheetSwapNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_名前の行に入れ替え欄が出てDの行には出ない(): void
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

        // 入れ替え欄はOPの1人ぶんだけ（Dの行には出さない）。
        $this->assertSame(1, substr_count($html, 'class="m-swapname"'));
        $this->assertStringContainsString('✕ 外す', $html);
        $this->assertStringContainsString('ecsSheetSwapMember', $html);
    }
}
