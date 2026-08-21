<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 稼働希望カレンダーの月を切り替えられる（2026-08-21 baba要望）。
 *
 * これまでは当月しか出せず、翌月分の希望を出してもらえなかった。
 * 切り替えられる範囲＝当月〜半年先（大型案件は早くに日程が決まるため・2026-08-21 baba）。
 */
class StaffPrefMonthSwitchTest extends TestCase
{
    use RefreshDatabase;

    /** 指定なし＝当月。前の月へは行けない（当月が下限）。 */
    public function test_default_is_this_month_and_has_no_previous(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $meta = $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()->original->getData()['prefMeta'];

        $this->assertSame((int) Carbon::now()->format('Y'), $meta['year']);
        $this->assertSame((int) Carbon::now()->format('n'), $meta['month']);
        $this->assertNull($meta['prev'], '当月より前へは行けない');
        $this->assertSame(Carbon::now()->addMonthNoOverflow()->format('Y-m'), $meta['next']);
    }

    /** ?period= で月を指定できる。 */
    public function test_period_switches_the_month(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $next = Carbon::now()->addMonthNoOverflow();

        $meta = $this->actingAsPerson($staff)->get('/staff-portal?period=' . $next->format('Y-m'))
            ->assertOk()->original->getData()['prefMeta'];

        $this->assertSame((int) $next->format('n'), $meta['month']);
        $this->assertSame(Carbon::now()->format('Y-m'), $meta['prev'], '当月へ戻れる');
    }

    /** 半年先までしか進めない。 */
    public function test_cannot_go_beyond_six_months(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $far = Carbon::now()->addMonthsNoOverflow(6);

        $meta = $this->actingAsPerson($staff)->get('/staff-portal?period=' . $far->format('Y-m'))
            ->assertOk()->original->getData()['prefMeta'];

        $this->assertNull($meta['next'], '半年先が上限');
    }
}
