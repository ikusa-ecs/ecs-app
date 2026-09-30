<?php

namespace Tests\Feature;

use App\Support\AssignmentRole;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表の「P」・日別ボードのポジションで SD を選べる（2026-09-30 baba要望「SDが選択肢から無くなった」）。
 * ⚠ 名簿の「できるポジション」（positionLabels）には SD を足さない（本人の申告の話なので別）。
 */
class AssignSdOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_選択肢にSDがありDの次に並ぶ(): void
    {
        $codes = array_keys(AssignmentRole::assignLabels());
        $this->assertSame(['D', 'SD'], array_slice($codes, 0, 2));
        $this->assertArrayNotHasKey('SD', AssignmentRole::positionLabels(), '名簿のできるポジションにはSDを足さない');
    }

    public function test_アサイン表と日別ボードでSDを選べる(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $date = Carbon::today()->addDays(5);
        ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $date->format('Y-m-d')]);

        $sheet = $this->actingAsPerson($me)->get('/assign-sheet?month='.$date->format('Y-m'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="SD"', $sheet);

        $board = $this->actingAsPerson($me)->get('/assign')->assertOk();
        $this->assertArrayHasKey('SD', $board->viewData('roleOptions'));
    }
}
