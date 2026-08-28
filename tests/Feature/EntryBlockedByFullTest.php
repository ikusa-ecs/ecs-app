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
 * 締切（満員）の判定は「確定」の人だけで数える（2026-08-28 baba決定）。
 *
 * 【なぜ変えたか】
 * ⚠ 以前は「仮」も数えていたため、アサイン表を取り込んでシートのメンバーが仮で入った瞬間に
 *   その案件が「締切・満員」になり、**スタッフがエントリーできなくなっていた**。
 *   仮＝まだ声掛け中で決まっていないので、その枠は募集を続ける。
 *
 * ⚠ カードの「割当済」の数は今までどおり仮も入れて数える（アサイン作業のための数字なので別物）。
 */
class EntryBlockedByFullTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProject(int $need, string $status, int $people): array
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
            'required_count' => $need,
        ]);

        for ($i = 1; $i <= $people; $i++) {
            $sid = 'S-83'.$i;
            PersonFactory::new()->create(['id' => $sid, 'role' => 'staff', 'office' => '東京']);
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $sid,
                'date' => $day->toDateString(), 'role' => 'FC', 'status' => $status,
            ]);
        }

        return [$p, $staff];
    }

    /** ⚠ 「仮」だけのときは締切にしない＝エントリーを続けて受けられる。 */
    public function test_tentative_members_do_not_close_it(): void
    {
        [$p, $staff] = $this->setUpProject(3, '仮', 3);

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $job = collect($jobs)->firstWhere('id', $p->id);

        $this->assertNotNull($job);
        $this->assertSame(0, $job['filled'], '「仮」を人数に数えてしまっている');
        $this->assertFalse(
            RecruitStatus::isFull($p->required_count, $job['filled']),
            '仮のメンバーだけで「締切・満員」になっている＝スタッフがエントリーできない'
        );
    }

    /** 「確定」になったら締切にする（本当に埋まったので）。 */
    public function test_confirmed_members_close_it(): void
    {
        [$p, $staff] = $this->setUpProject(3, '確定', 3);

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $job = collect($jobs)->firstWhere('id', $p->id);

        $this->assertSame(3, $job['filled']);
        $this->assertTrue(RecruitStatus::isFull($p->required_count, $job['filled']));
    }

    /** 運営人数を増やせば、また募集中に戻る（追加募集）。 */
    public function test_raising_the_need_makes_it_open_again(): void
    {
        $this->assertTrue(RecruitStatus::isFull(3, 3));
        $this->assertFalse(RecruitStatus::isFull(5, 3), '人数を増やしても締切のままになっている');
    }

    /** 日別ボードも「確定」だけで判定する仕掛けになっている。 */
    public function test_board_counts_confirmed_only(): void
    {
        [$p, $staff] = $this->setUpProject(3, '仮', 3);
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertSee('function confirmedOf', false)
            ->assertSee("m.status === '確定'", false);
    }
}
