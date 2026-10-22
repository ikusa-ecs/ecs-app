<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 集計（/stats）の「規模別エントリー数」（2026-10-22 baba要望）。
 *
 * ⚠ 守りたいのは、拠点で絞ったときの数え方（baba決定）。
 *   案件数＝その拠点の案件／人＝本人の所属がその拠点の人（他拠点の案件へのエントリーも数える）。
 */
class StatsEntryScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_by_scale_with_office_rules(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false, 'role' => 'employee',
        ]);
        $tokyo = PersonFactory::new()->create(['name' => '東京の人', 'role' => 'staff', 'office' => '東京']);
        $nagoya = PersonFactory::new()->create(['name' => '名古屋の人', 'role' => 'staff', 'office' => '名古屋']);

        $date = Carbon::today()->startOfMonth()->toDateString();
        $big = ProjectFactory::new()->create(['start_date' => $date, 'status' => '未着手', 'office' => '東京', 'scale' => '大型']);
        $small = ProjectFactory::new()->create(['start_date' => $date, 'status' => '未着手', 'office' => '東京', 'scale' => '']);
        $nagoyaBig = ProjectFactory::new()->create(['start_date' => $date, 'status' => '未着手', 'office' => '名古屋', 'scale' => '大型']);

        Application::create(['project_id' => $big->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);
        Application::create(['project_id' => $small->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);
        Application::create(['project_id' => $nagoyaBig->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);   // 他拠点の案件へのエントリーも数える
        Application::create(['project_id' => $small->id, 'staff_id' => $nagoya->id, 'intent' => '希望']);     // 所属が名古屋なので東京では数えない

        $es = $this->actingAsPerson($admin)->get('/stats?office=東京')->assertOk()
            ->assertSee('規模別エントリー数')->original->getData()['entryScale'];

        $this->assertSame(['小型' => 1, '中型' => 0, '大型' => 1], $es['projects'], '案件数は東京の案件だけ（空の規模は小型）');
        $this->assertSame(['小型' => 1, '中型' => 0, '大型' => 2], $es['entries']);
        $this->assertSame(1, $es['unset']);
        $this->assertCount(1, $es['rows']);
        $this->assertSame('東京の人', $es['rows'][0]['name']);
        $this->assertSame(0, $es['zero']);
    }

    public function test_claude_check_account_is_hidden(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false, 'role' => 'employee',
        ]);
        PersonFactory::new()->create([
            'name' => 'Claude確認用', 'email' => 'onuma+claude@ikusa.co.jp', 'role' => 'employee', 'office' => '東京',
        ]);

        $members = $this->actingAsPerson($admin)->get('/stats?office=東京')->assertOk()->original->getData()['members'];

        $this->assertTrue($members->contains('name', '集計担当'));
        $this->assertFalse($members->contains('name', 'Claude確認用'), '確認用アカウントが集計に出ています。');
    }
}
