<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 社員・ディレクター集計（/projects-agg）を月ごとにする（2026-09-02 baba要望）。
 *
 * ⚠ それまでは**全期間の合計**だったので、「今月は誰が多いか」が読めなかった。
 *   D決めの「担当バランス」は月単位なので、同じ画面を見ていても**数が合わなかった**。
 * ⚠ 数えるのは**案件の開催日**が対象の月にあるもの（アサインの日ではない）。
 *   2日案件でも1件として数えるため。
 */
class ProjectsAggMonthTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create([
            'id' => 'E-ADM', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** その月に開催する案件のDだけを数える。 */
    private function makeD(string $projectId, string $date, string $staffId): void
    {
        Project::create([
            'id' => $projectId, 'project_name' => '運動会', 'content_names' => ['運動会'],
            'start_date' => $date, 'office' => '東京', 'status' => '確定', 'format' => 'リアル',
        ]);
        Assignment::create([
            'project_id' => $projectId, 'staff_id' => $staffId,
            'date' => $date, 'role' => 'D', 'status' => '確定',
        ]);
    }

    /** 既定は今月。先月の案件は数えない。 */
    public function test_it_counts_only_this_month_by_default(): void
    {
        $me = $this->admin();
        $thisMonth = Carbon::today()->startOfMonth()->addDays(5);
        $lastMonth = Carbon::today()->startOfMonth()->subMonth()->addDays(5);

        $this->makeD('P-NOW', $thisMonth->toDateString(), 'E-ADM');
        $this->makeD('P-OLD', $lastMonth->toDateString(), 'E-ADM');

        $this->actingAsPerson($me)->get('/projects-agg')
            ->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['d'] === 1 && $s['projects'] === 1);
    }

    /** ?ym= で月を切り替えられる。 */
    public function test_the_month_can_be_switched(): void
    {
        $me = $this->admin();
        $lastMonth = Carbon::today()->startOfMonth()->subMonth()->addDays(5);

        $this->makeD('P-NOW', Carbon::today()->startOfMonth()->addDays(5)->toDateString(), 'E-ADM');
        $this->makeD('P-OLD', $lastMonth->toDateString(), 'E-ADM');

        $this->actingAsPerson($me)->get('/projects-agg?ym='.$lastMonth->format('Y-m'))
            ->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['d'] === 1)
            ->assertViewHas('periodLabel', $lastMonth->format('Y年n月'));
    }

    /**
     * ⚠ 読めない形の月は今月として扱う（勝手な月にしない）。
     *   ここが緩いと、去年の数字が黙って出る。
     */
    public function test_a_broken_month_falls_back_to_this_month(): void
    {
        $me = $this->admin();

        $this->actingAsPerson($me)->get('/projects-agg?ym=めちゃくちゃ')
            ->assertOk()
            ->assertViewHas('period', Carbon::today()->format('Y-m'));
    }

    /** 月の切替が画面に出ている。 */
    public function test_the_screen_has_the_month_switch(): void
    {
        $me = $this->admin();

        $this->actingAsPerson($me)->get('/projects-agg')
            ->assertOk()
            ->assertSee(Carbon::today()->format('Y年n月'))
            ->assertSee('今月')
            ->assertSee('に開催する案件」だけ', false);
    }
}
