<?php

namespace Tests\Feature;

use App\Models\SheetSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Database\Factories\PersonFactory;
use Tests\TestCase;

/**
 * 受信箱の「取り込み済みの月を隠す」（2026-10-28 baba「過去の案件で全部クリアになったら受信箱には要らない」）。
 * ⚠ 記録は消さない。画面で隠すだけ（案件IDや紐づけの記録を使い続けるため）。
 */
class SheetInboxHideDoneTest extends TestCase
{
    use RefreshDatabase;

    private function sync(string $period, ?Carbon $applied, ?Carbon $changed): SheetSync
    {
        $s = SheetSync::create([
            'source' => 'gas', 'tab' => str_replace('-', '', $period), 'period' => $period,
            'office' => '東京', 'rows' => [[]], 'case_count' => 1,
            'fingerprint' => 'x'.$period, 'received_at' => Carbon::now(),
        ]);
        $s->forceFill(['applied_at' => $applied, 'changed_at' => $changed])->save();

        return $s;
    }

    public function test_反映済みで変わっていない月だけ隠す印が付く(): void
    {
        $manager = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'manager', 'office' => '東京']);
        $now = Carbon::now();
        $this->sync('2026-01', $now->copy()->subDay(), null);                       // 反映済み・変わっていない → 隠す
        $this->sync('2026-02', $now->copy()->subDay(), $now);                       // 反映後に変わった → 出す
        $this->sync('2026-03', null, null);                                         // まだ反映していない → 出す

        $html = $this->actingAsPerson($manager)->get('/sheet-inbox?office=東京')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-applied="1"'));
        $this->assertSame(2, substr_count($html, 'data-applied="0"'));
        $this->assertStringContainsString('id="sxHideDone"', $html);
    }
}
