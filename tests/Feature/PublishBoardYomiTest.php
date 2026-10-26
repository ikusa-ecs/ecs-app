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
