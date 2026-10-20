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
        // ⚠ 日付は今日から数える（2026-10-21）。固定の日付（7/14→7/19）だと、
        //   日がたって「3か月より前は読まない」の外に出たときに落ちる（隣のテストと同じ直し）。
        $today = Carbon::today();

        $p = ProjectFactory::new()->create([
            'start_date' => $today->copy()->addDays(5)->format('Y-m-d'),   // 5日後
        ]);

        $case = PersonalCases::cases($today)->firstWhere('id', $p->id);

        $this->assertSame(5, $case['off'], '開催5日後なら off=5');
        $this->assertFalse($case['archived']);
    }

    /** 過去の開催日は off が負の日数、archived=true。 */
    public function test_past_project_has_negative_off_and_is_archived(): void
    {
        // ⚠ 日付は今日から数える（2026-10-13）。固定の日付だと「3か月より前は読まない」
        //   （RecentWindow＝本当の今日から数える）の外に出て、月日が経つと落ちる。
        $today = Carbon::today();

        $p = ProjectFactory::new()->create([
            'start_date' => $today->copy()->subDays(3)->toDateString(),   // 3日前
        ]);

        $case = PersonalCases::cases($today)->firstWhere('id', $p->id);

        $this->assertSame(-3, $case['off'], '開催3日前なら off=-3');
        $this->assertTrue($case['archived']);
    }

    /** 開催日＝今日なら off=0、archived=false。 */
    public function test_today_project_has_zero_off(): void
    {
        // ⚠ 日付は今日から数える（固定の日付だと RecentWindow＝3か月の外に出て落ちる・2026-10-15に実際に落ちた）。
        $today = Carbon::today();

        $p = ProjectFactory::new()->create([
            'start_date' => $today->toDateString(),
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
