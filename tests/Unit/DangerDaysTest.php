<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Support\DangerDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 危険日（手動指定）を「拠点ごと」と「全拠点」の2通りで持てるようにした（2026-08-26 baba要望）。
 *
 * ・全拠点 … どの拠点の画面にも出る。キーは**今までのまま**（昔の危険日はここに残る）
 * ・その拠点だけ … その拠点の画面にだけ出る
 * 画面に出すのは、いつも「全拠点 ＋ その拠点」を合わせたもの。
 */
class DangerDaysTest extends TestCase
{
    use RefreshDatabase;

    /** 全拠点の危険日は、どの拠点で見ても出る。 */
    public function test_all_offices_dates_show_everywhere(): void
    {
        DangerDays::saveAllOffices(['2026-09-20']);

        $this->assertSame(['2026-09-20'], DangerDays::dates('東京'));
        $this->assertSame(['2026-09-20'], DangerDays::dates('東北'));
    }

    /** その拠点だけの危険日は、他の拠点では出ない。 */
    public function test_office_dates_show_only_in_that_office(): void
    {
        DangerDays::saveOffice(['2026-09-21'], '東北');

        $this->assertSame(['2026-09-21'], DangerDays::dates('東北'));
        $this->assertSame([], DangerDays::dates('東京'));
    }

    /** 画面に出すのは「全拠点 ＋ その拠点」を合わせたもの（重複は1つ・昇順）。 */
    public function test_dates_merge_all_offices_and_own_office(): void
    {
        DangerDays::saveAllOffices(['2026-09-20', '2026-09-22']);
        DangerDays::saveOffice(['2026-09-21', '2026-09-22'], '東京');

        $this->assertSame(['2026-09-20', '2026-09-21', '2026-09-22'], DangerDays::dates('東京'));
    }

    /**
     * 拠点で絞らないとき（管理者が「全拠点」で見ているとき）は、
     * どこかの拠点で危険日ならまとめて出す。
     */
    public function test_dates_without_office_include_every_office(): void
    {
        DangerDays::saveAllOffices(['2026-09-20']);
        DangerDays::saveOffice(['2026-09-21'], '東京');
        DangerDays::saveOffice(['2026-09-23'], '東北');

        $this->assertSame(['2026-09-20', '2026-09-21', '2026-09-23'], DangerDays::dates());
    }

    /**
     * 昔から登録してあった危険日は、そのまま「全拠点の危険日」として読める。
     * ⚠ ここが崩れると、切り替えた瞬間に今までの危険日が全部消える
     *   （だから全拠点共通はキーを変えていない＝移行の作業が要らない）。
     */
    public function test_existing_dates_become_all_offices_dates(): void
    {
        Setting::put(DangerDays::KEY, json_encode(['2026-09-20']));

        $this->assertSame(['2026-09-20'], DangerDays::allOfficesDates());
        $this->assertSame(['2026-09-20'], DangerDays::dates('東北'));
    }

    /** 保存は重複を除き、空を捨て、昇順にそろえる。 */
    public function test_save_deduplicates_and_sorts(): void
    {
        $saved = DangerDays::saveOffice(['2026-09-21', '2026-09-20', '2026-09-21', ' 2026-09-19 ', ''], '東京');

        $this->assertSame(['2026-09-19', '2026-09-20', '2026-09-21'], $saved);
    }
}
