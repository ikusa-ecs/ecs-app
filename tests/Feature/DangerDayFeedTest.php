<?php

namespace Tests\Feature;

use App\Support\DangerCalendar;
use App\Support\DangerDayRule;
use App\Support\DangerDays;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 危険日をイベプラのカレンダーに入れる仕掛け（2026-09-16 baba要望）の見張り。
 *
 * ECSがやるのは「どの日が危険日か」「何と書くか」を返すことだけ。
 * 実際にカレンダーへ入れるのはGAS（手順書＝`稼働管理\ECS\危険日をカレンダーに入れるGAS.txt`）。
 *
 * ⚠ いちばん大事な見張り＝**画面のJSと同じ数字で判定しているか**。
 *   画面（ダッシュボード）が赤くする日と、カレンダーに入る日が食い違うと誰も信用しなくなる。
 */
class DangerDayFeedTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'danger-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ecs.sheet_sync_token' => self::TOKEN]);
    }

    /** 大型2件が重なった日を作る（＝自動判定の危険日になる）。 */
    private function twoBigEventsOn(string $date): void
    {
        foreach (['大型その1', '大型その2'] as $i => $name) {
            ProjectFactory::new()->create([
                'id' => 'P-BIG-'.$i,
                'project_name' => $name,
                'office' => '東京',
                'scale' => '大型',
                'status' => '調整中',
                'format' => 'イベント東(リアル)',
                'required_count' => 10,
                'start_date' => $date,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 門（合言葉）
    // ------------------------------------------------------------------

    /** 合言葉を決めていなければ、入口そのものが閉じている。 */
    public function test_the_feed_is_closed_when_no_token_is_configured(): void
    {
        config(['ecs.sheet_sync_token' => '']);

        $this->getJson('/danger-days?token=なんでも')->assertStatus(404);
    }

    /** 合言葉が違えば返さない。 */
    public function test_a_wrong_token_is_refused(): void
    {
        $this->getJson('/danger-days?token=ちがう')->assertStatus(403);
    }

    /** 知らない拠点名は受け取らない（勘で東京にしない）。 */
    public function test_an_unknown_office_is_refused(): void
    {
        $this->getJson('/danger-days?token='.self::TOKEN.'&office=どこか')->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // 中身
    // ------------------------------------------------------------------

    /** 自動判定の危険日（大型2件）が、理由つきで返る。 */
    public function test_it_returns_an_automatic_danger_day_with_reasons(): void
    {
        $date = Carbon::today()->addDays(20)->format('Y-m-d');
        $this->twoBigEventsOn($date);

        $json = $this->getJson('/danger-days?token='.self::TOKEN.'&office=東京')
            ->assertOk()->json();

        $day = collect($json['days'])->firstWhere('date', $date);
        $this->assertNotNull($day, '危険日として返る');
        $this->assertSame('自動', $day['kind']);
        $this->assertStringContainsString('大型案件が2件', $day['reasons'][0]);
        $this->assertContains('大型その1', $day['projects']);
    }

    /** 共通設定で手で足した危険日も返る（案件が無い日でも）。 */
    public function test_it_returns_a_manually_marked_danger_day(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        DangerDays::saveOffice([$date], '東京');

        $json = $this->getJson('/danger-days?token='.self::TOKEN.'&office=東京')
            ->assertOk()->json();

        $day = collect($json['days'])->firstWhere('date', $date);
        $this->assertNotNull($day);
        $this->assertSame('手動', $day['kind']);
    }

    /** 自動と手動が同じ日に重なったら「自動と手動」と分かる。 */
    public function test_it_marks_a_day_that_is_both_automatic_and_manual(): void
    {
        $date = Carbon::today()->addDays(20)->format('Y-m-d');
        $this->twoBigEventsOn($date);
        DangerDays::saveOffice([$date], '東京');

        $json = $this->getJson('/danger-days?token='.self::TOKEN.'&office=東京')
            ->assertOk()->json();

        $this->assertSame('自動と手動', collect($json['days'])->firstWhere('date', $date)['kind']);
    }

    /** 範囲の外（既定は6か月先まで）の危険日は返さない。 */
    public function test_it_does_not_look_further_than_asked(): void
    {
        $far = Carbon::today()->addMonthsNoOverflow(9)->format('Y-m-d');
        $this->twoBigEventsOn($far);

        $json = $this->getJson('/danger-days?token='.self::TOKEN.'&office=東京')
            ->assertOk()->json();

        $this->assertNull(collect($json['days'])->firstWhere('date', $far));
    }

    /** 予定の中身＝タイトル・時間・目印・説明文がそろって返る。 */
    public function test_it_returns_the_text_for_the_calendar_entry(): void
    {
        $date = Carbon::today()->addDays(20)->format('Y-m-d');
        $this->twoBigEventsOn($date);

        $json = $this->getJson('/danger-days?token='.self::TOKEN.'&office=東京')
            ->assertOk()->json();

        $this->assertSame(DangerCalendar::TITLE_DEFAULT, $json['title']);
        $this->assertSame('10:00', $json['start']);
        $this->assertSame('19:00', $json['end']);
        $this->assertSame(DangerCalendar::MARK, $json['mark']);

        $body = collect($json['days'])->firstWhere('date', $date)['body'];
        $this->assertStringContainsString('他の日程で調整できる商談', $body, 'baba の文面');
        $this->assertStringContainsString('大型案件が2件', $body, '危険日と判定した理由');
        $this->assertStringContainsString(DangerCalendar::MARK, $body, 'ECSが入れた予定の目印');
    }

    /** 共通設定で文面を直すと、返す中身も変わる。 */
    public function test_the_text_can_be_changed_from_the_settings_screen(): void
    {
        $date = Carbon::today()->addDays(20)->format('Y-m-d');
        $this->twoBigEventsOn($date);

        $admin = \Database\Factories\PersonFactory::new()->create([
            'role' => 'employee', 'permission' => 'admin', 'office' => '東京',
            'must_onboard' => false, 'active' => true,
        ]);
        $this->actingAsPerson($admin)->post('/settings/danger-calendar', [
            'title' => '【ECS】この日は動かせません',
            'body' => 'この日は商談を入れないでください。',
        ])->assertRedirect();

        $json = $this->getJson('/danger-days?token='.self::TOKEN.'&office=東京')
            ->assertOk()->json();

        $this->assertSame('【ECS】この日は動かせません', $json['title']);
        $this->assertStringContainsString(
            'この日は商談を入れないでください。',
            collect($json['days'])->firstWhere('date', $date)['body']
        );
    }

    /** 共通設定の画面に、文面を直す欄が出ている（詰め替え漏れの見張り）。 */
    public function test_the_settings_screen_has_the_text_fields(): void
    {
        $admin = \Database\Factories\PersonFactory::new()->create([
            'role' => 'employee', 'permission' => 'admin', 'office' => '東京',
            'must_onboard' => false, 'active' => true,
        ]);

        $html = $this->actingAsPerson($admin)->get('/settings')->assertOk()->getContent();

        $this->assertStringContainsString('危険日をカレンダーに入れるときの文面', $html);
        $this->assertStringContainsString('action="/settings/danger-calendar"', $html);
        $this->assertStringContainsString(DangerCalendar::START_TIME, $html, '10:00〜19:00 と出す');
    }

    /** タイトルを空で保存したら初期値に戻る（名前のない予定を作らないため）。 */
    public function test_an_empty_title_falls_back_to_the_default(): void
    {
        DangerCalendar::saveTitle('');

        $this->assertSame(DangerCalendar::TITLE_DEFAULT, DangerCalendar::title());
    }

    // ------------------------------------------------------------------
    // ⚠ 画面のJSと同じ数字か（いちばん大事）
    // ------------------------------------------------------------------

    /**
     * 判定の数字が、画面のJS（public/ecs/data/cases.js の ECS_dangerCheck）と同じであること。
     *
     * ⚠ ここが落ちたら「片方だけ直した」ということ。**両方を同じ数字にそろえてから**直す。
     *   （画面が赤くする日と、カレンダーに入る日が食い違うのを防ぐ見張り）
     */
    public function test_the_numbers_match_the_screen_javascript(): void
    {
        $js = (string) file_get_contents(public_path('ecs/data/cases.js'));

        $this->assertStringContainsString(
            'if (big >= '.DangerDayRule::BIG_MIN.')', $js,
            '大型案件の件数（cases.js と DangerDayRule）'
        );
        $this->assertStringContainsString(
            'if (real >= '.DangerDayRule::REAL_MIN.')', $js,
            'リアル系案件の件数'
        );
        $this->assertStringContainsString(
            'staff * '.DangerDayRule::STAFF_RATIO, $js,
            'スタッフ数の何割か'
        );
        $this->assertStringContainsString(
            'window.ECS_ACTIVE_STAFF = '.DangerDayRule::ACTIVE_STAFF.';', $js,
            '想定しているスタッフ数'
        );
    }

    /** 判定そのもの（3つの決まり）が、決めたとおりに働く。 */
    public function test_the_rule_itself(): void
    {
        $big = array_fill(0, DangerDayRule::BIG_MIN, ['scale' => '大型', 'fmt' => 'online', 'need' => 0]);
        $this->assertTrue(DangerDayRule::check($big)['danger'], '大型が重なった日');

        $real = array_fill(0, DangerDayRule::REAL_MIN, ['scale' => '', 'fmt' => 'real', 'need' => 0]);
        $this->assertTrue(DangerDayRule::check($real)['danger'], 'リアル系が重なった日');

        $threshold = (int) ceil(DangerDayRule::ACTIVE_STAFF * DangerDayRule::STAFF_RATIO);
        $heavy = [['scale' => '', 'fmt' => 'online', 'need' => $threshold + 1]];
        $this->assertTrue(DangerDayRule::check($heavy)['danger'], '必要スタッフ数が多い日');

        $quiet = [['scale' => '', 'fmt' => 'real', 'need' => 5]];
        $this->assertFalse(DangerDayRule::check($quiet)['danger'], 'ふつうの日は危険日にしない');
    }
}
