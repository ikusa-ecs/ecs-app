<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectDispatch;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 集計（/stats）の「のべ出勤数」の内訳＝スタッフ／社員／派遣（2026-10-08 baba「全員に渡すお水の量を事前に知りたい」）。
 *
 * ⚠ 守りたいのは2つ。
 *   ① 派遣は名簿に入らない＝assignmentsに出てこない。project_dispatches から人数ぶん足す（キャンセルは除く）。
 *   ② のべ出勤数（大きい数字・昨対比）そのものには派遣を混ぜない＝これまでの数字を変えない。
 */
class StatsAttendanceBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_breakdown_counts_staff_employee_and_dispatch(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
            'role' => 'employee',
        ]);
        $staff = PersonFactory::new()->create(['name' => 'スタッフA', 'role' => 'staff', 'office' => '東京']);

        $date = Carbon::today()->startOfMonth()->toDateString();
        $project = ProjectFactory::new()->create([
            'start_date' => $date, 'status' => '未着手', 'office' => '東京', 'project_name' => 'お水の案件',
        ]);

        Assignment::create(['project_id' => $project->id, 'staff_id' => $staff->id, 'date' => $date, 'role' => 'OP', 'status' => '確定']);
        Assignment::create(['project_id' => $project->id, 'staff_id' => $admin->id, 'date' => $date, 'role' => 'D', 'status' => '仮']);
        ProjectDispatch::create(['project_id' => $project->id, 'agency' => 'A社', 'count' => 10, 'status' => '依頼中']);
        ProjectDispatch::create(['project_id' => $project->id, 'agency' => 'B社', 'count' => 5, 'status' => 'キャンセル']);

        $data = $this->actingAsPerson($admin)->get('/stats')->assertOk()->original->getData();

        $this->assertSame(1, $data['attendanceStaff']);
        $this->assertSame(1, $data['attendanceEmployee']);
        $this->assertSame(10, $data['attendanceDispatch'], '派遣の人数（キャンセル除く）が内訳に入っていません。');
        $this->assertSame(2, $data['totalAttendance'], 'のべ出勤数に派遣が混ざっています（これまでの数字が変わります）。');
    }
}
