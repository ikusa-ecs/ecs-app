<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ公開ボードの札（大型・オンライン・前泊有）。案件一覧と同じ決まり（2026-10-26 baba要望）。
 */
class PublishBoardBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_board_carries_big_online_and_stay_flags(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        ProjectFactory::new()->published()->create([
            'office' => '東京', 'scale' => '大型', 'format' => 'オンライン', 'lodging' => '前泊有',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);

        $html = $this->actingAsPerson($me)->get('/assign-publish')->assertOk()->getContent();

        $this->assertStringContainsString('"big":true', $html);
        $this->assertStringContainsString('"online":true', $html);
        $this->assertStringContainsString('"stay":true', $html);
    }
}
