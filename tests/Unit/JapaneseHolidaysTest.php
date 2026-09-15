<?php

namespace Tests\Unit;

use App\Support\JapaneseHolidays;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 祝日の計算（App\Support\JapaneseHolidays）。
 *
 * ⚠ このテストがある理由（2026-09-15）
 *   社員の出勤可能日カレンダーが祝日を月日べた書きで持っていて、2025年の日付のまま
 *   2026年を表示していた（社員から「祝日が正しく反映されない」と報告）。
 *   年が変わるとズレる、という同じ事故をくり返さないための見張り。
 */
class JapaneseHolidaysTest extends TestCase
{
    public function test_2026年の秋の祝日が正しい(): void
    {
        $h = JapaneseHolidays::forYear(2026);

        // 報告された不具合そのもの。2026年の敬老の日は 9/21、秋分は 9/23、スポーツの日は 10/12。
        $this->assertSame('敬老の日', $h['2026-9-21'] ?? null);
        $this->assertSame('秋分の日', $h['2026-9-23'] ?? null);
        $this->assertSame('スポーツの日', $h['2026-10-12'] ?? null);

        // 画面にべた書きされていた「2025年の日付」が混ざっていないこと。
        $this->assertArrayNotHasKey('2026-9-15', $h);
        $this->assertArrayNotHasKey('2026-10-13', $h);
    }

    public function test_祝日にはさまれた平日は国民の休日になる(): void
    {
        $h = JapaneseHolidays::forYear(2026);

        // 2026/9/22（火）は 敬老の日(9/21) と 秋分の日(9/23) にはさまれるので休み。
        $this->assertSame('国民の休日', $h['2026-9-22'] ?? null);
    }

    public function test_日曜の祝日は振替休日になる(): void
    {
        $h = JapaneseHolidays::forYear(2026);

        // 2026/5/3（憲法記念日）は日曜。5/4・5/5 も祝日なので、振替は 5/6。
        $this->assertSame('憲法記念日', $h['2026-5-3'] ?? null);
        $this->assertSame('振替休日', $h['2026-5-6'] ?? null);
    }

    public function test_年が変わればハッピーマンデーも動く(): void
    {
        $y2025 = JapaneseHolidays::forYear(2025);
        $y2027 = JapaneseHolidays::forYear(2027);

        // 2025年＝敬老の日 9/15・スポーツの日 10/13（これが画面にべた書きされていた値）
        $this->assertSame('敬老の日', $y2025['2025-9-15'] ?? null);
        $this->assertSame('スポーツの日', $y2025['2025-10-13'] ?? null);

        // 2027年＝敬老の日 9/20・スポーツの日 10/11
        $this->assertSame('敬老の日', $y2027['2027-9-20'] ?? null);
        $this->assertSame('スポーツの日', $y2027['2027-10-11'] ?? null);
    }

    public function test_動かない祝日はそのまま(): void
    {
        $h = JapaneseHolidays::forYear(2026);

        $this->assertSame('元日', $h['2026-1-1'] ?? null);
        $this->assertSame('建国記念の日', $h['2026-2-11'] ?? null);
        $this->assertSame('昭和の日', $h['2026-4-29'] ?? null);
        $this->assertSame('山の日', $h['2026-8-11'] ?? null);
        $this->assertSame('勤労感謝の日', $h['2026-11-23'] ?? null);
    }

    public function test_画面に渡すのは前後の年もふくむ(): void
    {
        $map = JapaneseHolidays::forDisplay(Carbon::create(2026, 9, 15));

        // カレンダーは前の月にも先の月にも動かせるので、去年から再来年まで入っている。
        $this->assertArrayHasKey('2025-1-1', $map);
        $this->assertArrayHasKey('2026-1-1', $map);
        $this->assertArrayHasKey('2027-1-1', $map);
        $this->assertArrayHasKey('2028-1-1', $map);
    }

    public function test_計算式の範囲外の年は空にする(): void
    {
        // 間違った日を出すより「祝日なし」のほうが安全（春分・秋分の式は2099年まで）。
        $this->assertSame([], JapaneseHolidays::forYear(2200));
    }

    public function test_その日の祝日名を引ける(): void
    {
        $this->assertSame('敬老の日', JapaneseHolidays::nameFor(Carbon::create(2026, 9, 21)));
        $this->assertNull(JapaneseHolidays::nameFor(Carbon::create(2026, 9, 24)));
    }
}
