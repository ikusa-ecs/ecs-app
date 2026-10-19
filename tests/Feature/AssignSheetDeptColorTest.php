<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表：社員の名前を部署の色にする（2026-10-19 baba要望）。
 * イベプラ＝オレンジ／セールス＝紺／クリエイティブ＝黄緑。スタッフ・その他は色なし。
 * 部署の見分けの正本＝App\Support\Departments::code（plan/sales/creative/other/none）。
 */
class AssignSheetDeptColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_社員の名前に部署のクラスが付きスタッフには付かない(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $manager = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '中型']);
        $plan = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'イベプラ社員', 'department' => 'イベプラ']);
        $sales = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'セールス社員', 'department' => 'セールス']);
        $cre = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'クリエ社員', 'department' => 'クリエイティブ']);
        $staff = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => 'ふつうスタッフ', 'department' => 'イベプラ']);
        foreach ([$plan, $sales, $cre, $staff] as $who) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $who->id, 'date' => $date, 'role' => 'OP', 'status' => '確定']);
        }

        $html = $this->actingAsPerson($manager)
            ->get('/assign-sheet?month='.Carbon::parse($date)->format('Y-m'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<span class="nm-dep dep-plan">イベプラ社員</span>', $html);
        $this->assertStringContainsString('<span class="nm-dep dep-sales">セールス社員</span>', $html);
        $this->assertStringContainsString('<span class="nm-dep dep-creative">クリエ社員</span>', $html);
        // スタッフは所属が入っていても色を付けない。
        $this->assertStringContainsString('<span class="nm-dep dep-">ふつうスタッフ</span>', $html);
    }
}
