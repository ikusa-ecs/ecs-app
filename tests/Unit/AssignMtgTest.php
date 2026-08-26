<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Support\AssignMtg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサインMTG日の基準日ロジック（AssignMtg）の単体テスト。テスト仕様書 UT-SET-01。
 *
 * current() は「今日までで一番新しいMTG日（過ぎた中で最新）」を返す。
 * この基準日が「追加案件」の自動判定に使われるため、正しく選ばれることを守る。
 * settings テーブルを読むため DB が必要（RefreshDatabase）。
 */
class AssignMtgTest extends TestCase
{
    use RefreshDatabase;

    /** save() は重複を除去し昇順に整えて保存し、dates() で読み戻せる。 */
    public function test_save_deduplicates_and_sorts_dates(): void
    {
        $saved = AssignMtg::save(['2026-07-01', '2026-06-01', '2026-07-01', ' 2026-05-01 ', '']);

        // 重複("2026-07-01")と空文字は除去、前後空白は trim、昇順。
        $this->assertSame(['2026-05-01', '2026-06-01', '2026-07-01'], $saved);
        $this->assertSame(['2026-05-01', '2026-06-01', '2026-07-01'], AssignMtg::dates());
    }

    /** current() は基準日以前で最も新しいMTG日を選ぶ。 */
    public function test_current_picks_latest_past_meeting(): void
    {
        AssignMtg::save(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01']);

        // 2026-07-15 時点では 07-01 が「過ぎた中で最新」。08-01（未来）は選ばない。
        $this->assertSame('2026-07-01', AssignMtg::current('2026-07-15'));
    }

    /** 基準日がMTG日ちょうどなら、その日自身が選ばれる（<= 判定）。 */
    public function test_current_includes_the_meeting_day_itself(): void
    {
        AssignMtg::save(['2026-06-01', '2026-07-01']);

        $this->assertSame('2026-07-01', AssignMtg::current('2026-07-01'));
    }

    /** 過ぎたMTG日が1つも無ければ null（＝自動判定しない）。 */
    public function test_current_returns_null_when_all_meetings_are_future(): void
    {
        AssignMtg::save(['2026-08-01', '2026-09-01']);

        $this->assertNull(AssignMtg::current('2026-07-15'));
    }

    /** 未登録なら dates() は空・current() は null。 */
    public function test_empty_when_nothing_saved(): void
    {
        $this->assertSame([], AssignMtg::dates());
        $this->assertNull(AssignMtg::current('2026-07-15'));
    }

    /** 後方互換：旧・単一キー（assign_mtg_date）しか無い場合も1件として拾う。 */
    public function test_falls_back_to_legacy_single_key(): void
    {
        Setting::put('assign_mtg_date', '2026-06-10');

        $this->assertSame(['2026-06-10'], AssignMtg::dates());
        $this->assertSame('2026-06-10', AssignMtg::current('2026-07-15'));
    }

    /**
     * MTG日は拠点ごとに別々に持てる（2026-08-26 baba要望）。
     * ⚠ 全国で1つしか持てなかったため、東京のMTG日で東北の案件まで「追加案件」と
     *   判定されていた。
     */
    public function test_dates_are_kept_per_office(): void
    {
        AssignMtg::save(['2026-07-01'], '東京');
        AssignMtg::save(['2026-07-20'], '東北');

        $this->assertSame(['2026-07-01'], AssignMtg::dates('東京'));
        $this->assertSame(['2026-07-20'], AssignMtg::dates('東北'));

        // 7/10 時点：東京は 7/1 が基準（過ぎた）／東北は 7/20 がまだ来ていないので基準なし。
        $this->assertSame('2026-07-01', AssignMtg::current('2026-07-10', '東京'));
        $this->assertNull(AssignMtg::current('2026-07-10', '東北'));
    }

    /** 拠点を書かずに保存・読み出しすると、既定の拠点（東京）として扱う。 */
    public function test_no_office_means_default_office(): void
    {
        AssignMtg::save(['2026-07-01']);

        $this->assertSame(['2026-07-01'], AssignMtg::dates('東京'));
    }

    /**
     * その拠点にまだ登録が無ければ、全国共通だった昔の値を読む。
     * ⚠ これが無いと、拠点ごとに切り替えた瞬間に基準日が消えて、
     *   その日から登録した案件が「追加案件」と判定されなくなる。
     */
    public function test_falls_back_to_nationwide_value_before_migration(): void
    {
        Setting::put('assign_mtg_dates', json_encode(['2026-06-01']));

        $this->assertSame(['2026-06-01'], AssignMtg::dates('名古屋'));
        $this->assertSame('2026-06-01', AssignMtg::current('2026-07-15', '名古屋'));
    }

    /** 案件登録の画面は拠点を切り替えられるので、拠点ごとの基準日をまとめて返す。 */
    public function test_current_by_office_returns_map(): void
    {
        AssignMtg::save(['2026-07-01'], '東京');
        AssignMtg::save(['2026-07-20'], '東北');

        $this->assertSame(
            ['東京' => '2026-07-01', '東北' => null],
            AssignMtg::currentByOffice(['東京', '東北'], '2026-07-10')
        );
    }
}
