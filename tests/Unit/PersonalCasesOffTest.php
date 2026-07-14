<?php

namespace Tests\Unit;

use App\Support\PersonalCases;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 開催日までの日数（off）の単体テスト。テスト仕様書 UT-RMD-01 相当。
 *
 * off ＝ 今日から開催日まで何日後か（マイナス＝過去）。archived は off<0。
 * 計算本体（toCase）は private のため、公開メソッド cases(Carbon $today) 経由で検証する。
 * cases() は Project を読むため DB が必要（RefreshDatabase）。
 */
class PersonalCasesOffTest extends TestCase
{
    use RefreshDatabase;

    /** 未来の開催日は off が正の日数、archived=false。 */
    public function test_future_project_has_positive_off(): void
    {
        $today = Carbon::parse('2026-07-14');

        $p = ProjectFactory::new()->create([
            'start_date' => '2026-07-19',   // 5日後
        ]);

        $case = PersonalCases::cases($today)->firstWhere('id', $p->id);

        $this->assertSame(5, $case['off'], '開催5日後なら off=5');
        $this->assertFalse($case['archived']);
    }

    /** 過去の開催日は off が負の日数、archived=true。 */
    public function test_past_project_has_negative_off_and_is_archived(): void
    {
        $today = Carbon::parse('2026-07-14');

        $p = ProjectFactory::new()->create([
            'start_date' => '2026-07-11',   // 3日前
        ]);

        $case = PersonalCases::cases($today)->firstWhere('id', $p->id);

        $this->assertSame(-3, $case['off'], '開催3日前なら off=-3');
        $this->assertTrue($case['archived']);
    }

    /** 開催日＝今日なら off=0、archived=false。 */
    public function test_today_project_has_zero_off(): void
    {
        $today = Carbon::parse('2026-07-14');

        $p = ProjectFactory::new()->create([
            'start_date' => '2026-07-14',
        ]);

        $case = PersonalCases::cases($today)->firstWhere('id', $p->id);

        $this->assertSame(0, $case['off']);
        $this->assertFalse($case['archived']);
    }

    /** 開催日が未設定（null）なら off=0（既定）。 */
    public function test_null_start_date_defaults_off_to_zero(): void
    {
        $today = Carbon::parse('2026-07-14');

        $p = ProjectFactory::new()->create([
            'start_date' => null,
        ]);

        $case = PersonalCases::cases($today)->firstWhere('id', $p->id);

        $this->assertSame(0, $case['off']);
    }
}
