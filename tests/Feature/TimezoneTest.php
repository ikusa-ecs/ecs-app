<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アプリの時刻は日本時間（Asia/Tokyo）であること。2026-08-27 babaから報告。
 *
 * 【なぜ見張るか】
 * Laravel の既定は UTC で、そのままだと日本時間より9時間遅れる。
 *   ① 編集履歴などに出る時刻が9時間前に見える
 *   ② ⚠ もっと危ないのは「今日」の判定。日本時間の朝0時〜9時のあいだ、
 *      アプリは「今日」を1日前だと思う。案件一覧の「◯日後」・自動アーカイブ・
 *      経験回数の「開催日が過ぎた」判定・危険日などが朝だけずれる。
 *
 * ⚠ ②は「朝しか起きない」ので、気づかないまま戻される危険がある。だからテストで止める。
 */
class TimezoneTest extends TestCase
{
    /** 設定が日本時間になっていること。 */
    public function test_app_timezone_is_tokyo(): void
    {
        $this->assertSame(
            'Asia/Tokyo',
            config('app.timezone'),
            'config/app.php の timezone を UTC に戻さないこと。'
                .'UTCだと日本時間の朝0〜9時に「今日」が1日前になり、案件の日数計算がずれる。'
        );
    }

    /** PHP 側の時刻も日本時間で動いていること。 */
    public function test_php_timezone_is_tokyo(): void
    {
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    /**
     * ⚠ これが本題：日本時間の朝（0〜9時）でも「今日」が今日であること。
     * UTCのままだと、この時刻では前日が返ってくる。
     */
    public function test_today_is_correct_in_the_early_morning(): void
    {
        // 日本時間の 2026-09-01 の朝2時に時計を固定する（UTCなら 8/31 17:00）。
        Carbon::setTestNow(Carbon::create(2026, 9, 1, 2, 0, 0, 'Asia/Tokyo'));

        $this->assertSame(
            '2026-09-01',
            Carbon::today()->toDateString(),
            '朝2時に「今日」が前日になっている＝タイムゾーンがUTCに戻っている'
        );

        Carbon::setTestNow();
    }

    /** 定期実行（リマインド）は日本時間で動くよう明示されていること。 */
    public function test_scheduler_is_pinned_to_tokyo(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString(
            "timezone('Asia/Tokyo')",
            $console,
            'リマインドの時刻指定から ->timezone(\'Asia/Tokyo\') を消さないこと'
        );
    }
}
