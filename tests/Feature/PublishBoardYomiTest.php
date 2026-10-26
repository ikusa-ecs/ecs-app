<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ公開ボードにも確度（Aヨミ/Bヨミ/Cヨミ）を出す（2026-10-26 baba要望）。
 */
class PublishBoardYomiTest extends TestCase
{
    use RefreshDatabase;

    // 画面のJSが確度・札を書き写しているか（書き写し忘れると、データがあっても印が出ない＝2026-10-26 0996）。
    public function test_public_board_script_copies_yomi_and_badges(): void
    {
        $view = file_get_contents(resource_path('views/assign_publish.blade.php'));
        $this->assertStringContainsString('yomi: c.yomi', $view);
        $this->assertStringContainsString('big: c.big', $view);
    }

    public function test_public_board_carries_the_yomi_for_each_case(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        ProjectFactory::new()->published()->create([
            'office' => '東京', 'yomi' => 'Aヨミ', 'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);

        $html = $this->actingAsPerson($me)->get('/assign-publish')->assertOk()->getContent();

        $this->assertStringContainsString('"yomi":'.json_encode('Aヨミ'), $html);
    }
}
