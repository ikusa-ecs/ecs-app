<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「イベント時間未定」（2026-08-21 baba要望）。
 *
 * 入場・開始・終了がまだ決まっていない案件があるので、空欄のままだと
 * 「入れ忘れ」と区別が付かない。チェックを1つ持たせ、案件一覧とスタッフ画面には
 * 「本番時間未定」と出す。
 */
class EventTimeTbdTest extends TestCase
{
    use RefreshDatabase;

    /** チェックを入れて登録すると、案件に「未定」として残る。 */
    public function test_checkbox_is_saved(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-09-01',
            'required_count' => '10',
            'event_time_tbd' => '1',
            'intent'         => 'publish',
        ]);

        $this->assertTrue((bool) Project::where('project_name', '水合戦')->first()->event_time_tbd);
    }

    /** チェックを入れずに登録すれば、これまでどおり「未定ではない」。 */
    public function test_default_is_not_tbd(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'    => '運動会',
            'start_date'       => '2026-09-02',
            'required_count'   => '10',
            'event_start_time' => '13:00',
            'intent'           => 'publish',
        ]);

        $project = Project::where('project_name', '運動会')->first();

        $this->assertFalse((bool) $project->event_time_tbd);
        $this->assertSame('13:00', $project->event_start_time);
    }

    /** 案件一覧が「本番時間未定」の印（evTbd）を画面に渡している。 */
    public function test_project_list_passes_the_flag(): void
    {
        $employee = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        \Database\Factories\ProjectFactory::new()->create([
            'project_name'   => '時間未定の案件',
            'start_date'     => '2026-09-03',
            'office'         => '東京',
            'event_time_tbd' => true,
        ]);

        $this->actingAsPerson($employee)->get('/projects')
            ->assertOk()
            ->assertSee('"evTbd":true', false);
    }
}
