<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use App\Support\RecruitStatus;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 【調査】アサイン表を取り込んで「仮」のメンバーが入ると、その案件が
 * スタッフの画面で「締切・満員」になり、**エントリーのボタンが押せなくなる**。
 * 「エントリーしても毎回未エントリーになる／記録も無い」の筋（2026-08-28 baba報告）。
 */
class EntryBlockedByFullTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠ 「仮」のメンバーも人数に数えているので、取込で入れただけで満員になる。 */
    public function test_tentative_members_make_it_look_full(): void
    {
        $staff = PersonFactory::new()->create([
            'id' => 'S-820', 'name' => '応募 次郎', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(15);

        $p = Project::create([
            'id' => 'P-FULL2', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => $day->toDateString(), 'office' => '東京', 'status' => '調整中',
            'is_recruiting' => true, 'staff_published' => true,
            'required_count' => 3,   // シートの「運営人数」
        ]);

        // 取込でシートのメンバーが「仮」で入った状態。
        foreach (['S-821', 'S-822', 'S-823'] as $sid) {
            PersonFactory::new()->create(['id' => $sid, 'role' => 'staff', 'office' => '東京']);
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $sid,
                'date' => $day->toDateString(), 'role' => 'FC', 'status' => '仮',
            ]);
        }

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $job = collect($jobs)->firstWhere('id', 'P-FULL2');

        $this->assertNotNull($job);
        $this->assertSame(3, $job['filled'], '「仮」のメンバーも人数に数えている');
        $this->assertTrue(
            RecruitStatus::isFull($p->required_count, $job['filled']),
            'この状態だとスタッフ画面では「締切・満員」になり、エントリーのボタンが押せない'
        );
    }

    /** 運営人数を増やせば、また押せるようになる（追加募集）。 */
    public function test_raising_the_need_makes_it_open_again(): void
    {
        $this->assertTrue(RecruitStatus::isFull(3, 3));
        $this->assertFalse(RecruitStatus::isFull(5, 3), '人数を増やしても締切のままになっている');
    }
}
