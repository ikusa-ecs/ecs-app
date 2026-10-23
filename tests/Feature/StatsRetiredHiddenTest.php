<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 集計ダッシュボード（/stats）で、退職した社員を表から外す見張り（baba 2026-10-23「退職者ははずしていい」）。
 *
 * ⚠ ここで守りたいのは2つ。
 *   ① 退職した社員は、社員別の表（画面・CSV）に出ない。
 *   ② それでも合計・部署の平均の数字は変わらない（退職者の出勤も数える）。
 */
class StatsRetiredHiddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_employee_is_hidden_from_table_but_counted_in_totals(): void
    {
        $project = ProjectFactory::new()->create([
            'start_date' => Carbon::today()->format('Y-m-d'), 'status' => '未着手', 'office' => '東京',
            'project_name' => 'テスト案件', 'format' => 'リアル',
        ]);

        $active = PersonFactory::new()->create([
            'name' => '在籍太郎', 'office' => '東京', 'department' => 'イベプラ', 'hire_date' => '2018-04-01',
        ]);
        $retired = PersonFactory::new()->create([
            'name' => '退職花子', 'office' => '東京', 'department' => 'イベプラ', 'hire_date' => '2019-04-01',
            'active' => false,
        ]);

        // 退職した人も、この月に出勤している（＝これまでは表に出ていた人）。
        foreach ([$active->id => 1, $retired->id => 2] as $staffId => $times) {
            for ($i = 0; $i < $times; $i++) {
                Assignment::create([
                    'project_id' => $project->id, 'staff_id' => $staffId,
                    'date' => Carbon::today()->addDays($i)->format('Y-m-d'),
                    'role' => 'OP', 'status' => '確定',
                ]);
            }
        }

        $me = PersonFactory::new()->create([
            'name' => 'ミルヒト管理者', 'permission' => 'manager', 'office' => '東京',
            'department' => '経営管理', 'hire_date' => null,
        ]);

        $response = $this->actingAsPerson($me)->get('/stats?office=all')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('在籍太郎', $html);
        $this->assertStringNotContainsString('退職花子', $html, '退職した社員は表に出さない');

        // 数字は変わらない＝イベプラの合計出勤は、退職者の2回も含めて3。
        $byDept = collect($response->original->getData()['byDept'])->firstWhere('dept', 'イベプラ');
        $this->assertSame(3, $byDept['count']);
        $this->assertSame(2, $byDept['headcount'], '平均の分母は、これまでどおり出勤した退職者も数える');
    }
}
