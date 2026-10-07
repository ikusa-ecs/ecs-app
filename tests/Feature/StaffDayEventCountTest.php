<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 稼働希望カレンダーの「その日のイベント◯件」（2026-10-07 スタッフ要望・baba決定）。
 *
 *  ・未公開の案件も数える（希望を出すのは公開より前だから）
 *  ・確度が「確定」（または空）だけ。Aヨミ等・キャンセル・下書き・他拠点は数えない
 *  ・画面に渡すのは件数だけ（案件名などは渡さない）
 */
class StaffDayEventCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_only_confirmed_projects_of_my_office_including_unpublished(): void
    {
        $month = Carbon::now()->startOfMonth();
        $d10 = $month->copy()->day(10)->toDateString();
        $d20 = $month->copy()->day(20)->toDateString();

        $base = ['start_date' => $d10, 'office' => '東京', 'yomi' => '確定'];
        ProjectFactory::new()->create($base);                                   // 未公開でも数える
        ProjectFactory::new()->published()->create($base);                      // 公開ずみ
        ProjectFactory::new()->create(['yomi' => ''] + $base);                  // 確度が空＝数える
        ProjectFactory::new()->create(['yomi' => 'Aヨミ'] + $base);             // ヨミ＝数えない
        ProjectFactory::new()->create(['is_cancelled' => true] + $base);        // キャンセル＝数えない
        ProjectFactory::new()->draft()->create($base);                          // 下書き＝数えない
        ProjectFactory::new()->create(['office' => '大阪'] + $base);            // 他拠点＝数えない
        ProjectFactory::new()->create(['start_date' => $d20] + $base);
        ProjectFactory::new()->create(['start_date' => $month->copy()->addMonth()->toDateString()] + $base); // 別の月

        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $data = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData();

        $this->assertSame([10 => 3, 20 => 1], $data['dayEventCounts']);
    }
}
