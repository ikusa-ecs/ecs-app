<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\ClockTime;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ向けの時間は「07:00」の形で保存する（2026-10-28）。
 * 「7:00」のままだと、案件の編集画面の時間欄に出せず、編集で時間が消えていた。
 */
class StaffTimeFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_pads_the_hour_and_keeps_other_text(): void
    {
        $this->assertSame('07:00', ClockTime::normalize('7:00'));
        $this->assertSame('14:45', ClockTime::normalize('14:45'));
        $this->assertNull(ClockTime::normalize('  '));
        $this->assertNull(ClockTime::normalize(null));
        $this->assertSame('未定', ClockTime::normalize('未定'), '時刻の形でないものは勝手に変えない');
    }

    public function test_publish_board_saves_padded_time(): void
    {
        $employee = PersonFactory::new()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-01']);

        $this->actingAsPerson($employee)->postJson('/assign-publish/time', [
            'id' => $project->id,
            'staff_meet' => '7:00',
        ])->assertOk();

        $this->assertSame('07:00', Project::find($project->id)->staff_meet_time);
    }
}
