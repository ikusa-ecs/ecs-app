<?php

namespace Tests\Feature;

use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 希望まとめ（/assign-wishlist）の月の切替を守るテスト（2026-09-07 baba要望）。
 *
 * それまでは**当月に固定**で、来月の希望をまとめて見ることができなかった。
 *
 * ⚠ 対象月の判定は shift_preferences.period（'2026-09' の形）で行う。
 *   ここが日付（date）判定にすり替わると、月をまたいで出した希望の扱いがずれる。
 * ⚠ 読めない ?period= は今月に倒す（去年の数字が黙って出るのを防ぐ）。
 */
class WishlistMonthTest extends TestCase
{
    use RefreshDatabase;

    private function wish(string $staffId, string $period, string $date): void
    {
        ShiftPreference::create([
            'staff_id' => $staffId, 'period' => $period,
            'date' => $date, 'availability' => '稼働可',
        ]);
    }

    /** ?period= で指定した月の希望者が出る。別の月の人は出ない。 */
    public function test_period_switches_the_month(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $now = PersonFactory::new()->staff()->create(['name' => '今月さん']);
        $next = PersonFactory::new()->staff()->create(['name' => '来月さん']);

        $thisMonth = Carbon::today()->startOfMonth();
        $nextMonth = $thisMonth->copy()->addMonth();
        $this->wish($now->id, $thisMonth->format('Y-m'), $thisMonth->format('Y-m-d'));
        $this->wish($next->id, $nextMonth->format('Y-m'), $nextMonth->format('Y-m-d'));

        $names = function (array $q) use ($me) {
            $data = $this->actingAsPerson($me)
                ->get('/assign-wishlist?' . http_build_query($q))->assertOk()->original->getData();

            return collect($data['people'])->pluck('name')->all();
        };

        $this->assertSame(['今月さん'], $names([]), '既定は今月');
        $this->assertSame(['来月さん'], $names(['period' => $nextMonth->format('Y-m')]), '来月に切り替わる');
    }

    /** 前後の月・今月かどうかが画面へ渡る（◀ ▶ と「今月」ボタンに使う）。 */
    public function test_month_links_are_passed(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);

        $data = $this->actingAsPerson($me)->get('/assign-wishlist?period=2026-05')
            ->assertOk()->original->getData();

        $this->assertSame('2026-05', $data['period']);
        $this->assertSame('2026年5月', $data['periodLabel']);
        $this->assertSame('2026-04', $data['prevPeriod']);
        $this->assertSame('2026-06', $data['nextPeriod']);
        $this->assertFalse($data['isThisMonth']);
    }

    /** ⚠ 読めない ?period= は今月に倒す（URLを手で書き換えられても壊れない）。 */
    public function test_broken_period_falls_back_to_this_month(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);

        foreach (['', 'zzz', '2026-13', '2026-00'] as $bad) {
            $data = $this->actingAsPerson($me)->get('/assign-wishlist?period=' . $bad)
                ->assertOk()->original->getData();

            $this->assertSame(Carbon::today()->format('Y-m'), $data['period'], "「{$bad}」は今月に倒す");
            $this->assertTrue($data['isThisMonth']);
        }
    }

    /** その月に希望が1件も無いとき、理由を画面に出す（空の表だけだと壊れて見える）。 */
    public function test_empty_month_explains_itself(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);

        $this->actingAsPerson($me)->get('/assign-wishlist?period=2020-01')
            ->assertOk()
            ->assertSee('まだ誰も稼働希望（〇）もエントリーも出していません', false);
    }
}
