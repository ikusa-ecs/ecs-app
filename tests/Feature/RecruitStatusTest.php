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
 * 「スタッフの画面にどう見えているか」を社員側の画面と合わせる（2026-08-28 baba指摘）。
 *
 * 【何が問題だったか】
 * 日別ボードは「公開しているか」だけを見て「募集中」と出していた。
 * ⚠ ところが**スタッフの画面では、人数が埋まった案件は「締切・満員」**になっていて
 *   エントリーできない。同じ案件が、社員から見ると募集中・スタッフから見ると締切でズレていた。
 *
 * ⚠ この状態はどこにも保存していない＝**運営人数を増やせば、その場でまた募集中に戻る**
 *   （追加募集のために公開し直す必要はない）。
 */
class RecruitStatusTest extends TestCase
{
    use RefreshDatabase;

    /** 人数が埋まっていれば満員。 */
    public function test_full_when_filled_reaches_the_need(): void
    {
        $this->assertTrue(RecruitStatus::isFull(5, 5));
        $this->assertTrue(RecruitStatus::isFull(5, 6));
        $this->assertFalse(RecruitStatus::isFull(5, 4));
        $this->assertSame(1, RecruitStatus::remaining(5, 4));
        $this->assertSame(0, RecruitStatus::remaining(5, 9));
    }

    /**
     * ⚠ 運営人数が未入力（空・0）のときは既定の人数を使う。
     * 0 のまま数えると、公開した瞬間に「満員」になってエントリーできなくなる。
     */
    public function test_missing_need_uses_the_default(): void
    {
        $this->assertSame(RecruitStatus::DEFAULT_NEED, RecruitStatus::need(0));
        $this->assertSame(RecruitStatus::DEFAULT_NEED, RecruitStatus::need(null));
        $this->assertFalse(RecruitStatus::isFull(null, 1), '人数未入力なのに満員になっている');
    }

    /** ⚠ 運営人数を増やすと、その場でまた募集中に戻る（公開し直さなくてよい）。 */
    public function test_raising_the_need_reopens_it(): void
    {
        $this->assertTrue(RecruitStatus::isFull(5, 5));
        $this->assertFalse(RecruitStatus::isFull(8, 5), '人数を増やしたのに締切のままになっている');
    }

    /** 日別ボードが、スタッフ画面と同じ必要人数を受け取っている。 */
    public function test_board_receives_the_staff_facing_need(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(6);

        Project::create([
            'id' => 'P-NEED', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true,
            // 運営人数は未入力のまま公開された、という状況。
        ]);

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertViewHas('boardCases', function ($cases) {
                $c = collect($cases)->firstWhere('id', 'P-NEED');

                return $c && $c['needStaff'] === RecruitStatus::DEFAULT_NEED;
            })
            // 画面側の判定と「追加募集する」の仕掛けが残っているか（@verbatim 対策）。
            ->assertSee('function isFullForStaff', false)
            ->assertSee('function addRecruit', false)
            ->assertSee('function recruitBadge', false);
    }

    /** スタッフ画面の「満員」の判定と、社員側の判定がそろっている。 */
    public function test_staff_screen_uses_the_same_number(): void
    {
        $staff = PersonFactory::new()->create([
            'id' => 'S-950', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(6);

        $p = Project::create([
            'id' => 'P-FULL', 'project_name' => '謎解き', 'content_names' => ['謎解き'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true, 'is_recruiting' => true,
            'required_count' => 2,
        ]);
        foreach (['S-951', 'S-952'] as $sid) {
            PersonFactory::new()->create(['id' => $sid, 'role' => 'staff', 'office' => '東京']);
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $sid,
                'date' => $day->toDateString(), 'role' => 'FC', 'status' => '確定',
            ]);
        }

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $job = collect($jobs)->firstWhere('id', 'P-FULL');

        $this->assertSame(2, $job['need']);
        $this->assertSame(2, $job['filled']);
        $this->assertTrue(RecruitStatus::isFull($p->required_count, $job['filled']),
            'スタッフ画面では満員なのに、社員側の判定では満員になっていない');
    }
}
