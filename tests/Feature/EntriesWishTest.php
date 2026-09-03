<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー一覧（/entries）で「その日、終日〇を出しているか」が分かること
 * （2026-09-03 baba要望）。
 *
 * ⚠ **エントリー（応募）と稼働希望カレンダーは別の入力**。両方を並べて見ないと
 *   「手は挙げてくれたが、その日はNGにしている」人に気づけず、アサインしてから断られる。
 *
 * カレンダー表示（📅 空いている人）は、baba の「カレンダーを見れば終日〇の人の名前が
 * 載っているのが理想」から。⚠ 出す人の決まりは日別ボードの希望者カラムとそろえてある
 * （スタッフだけ・在籍中・自拠点）。ずれると「ボードには出るのにカレンダーには出ない」になる。
 */
class EntriesWishTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::today()->addDays(10);
    }

    private function emp(string $permission = 'manager'): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-VIEW', 'role' => 'employee', 'permission' => $permission,
            'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
    }

    private function staff(string $id, string $name, ?string $office = '東京', bool $active = true): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'role' => 'staff', 'permission' => 'staff',
            'office' => $office, 'must_onboard' => false, 'active' => $active,
        ]);
    }

    private function wants(string $staffId, string $availability = '稼働可'): void
    {
        ShiftPreference::create([
            'staff_id' => $staffId, 'period' => $this->day->format('Y-m'),
            'date' => $this->day->format('Y-m-d'), 'availability' => $availability,
        ]);
    }

    private function project(): string
    {
        ProjectFactory::new()->create([
            'id' => 'P-W', 'project_name' => 'テスト案件', 'office' => '東京',
            'start_date' => $this->day->format('Y-m-d'), 'status' => '調整中', 'required_count' => 3,
        ]);

        return 'P-W';
    }

    /** 応募者の行に、その日の希望（終日〇／NG）が付くこと。 */
    public function test_each_entrant_shows_the_wish_for_that_day(): void
    {
        $pid = $this->project();
        $this->staff('S-OK', 'マルの人');
        $this->staff('S-NG', 'エヌジーの人');
        $this->staff('S-NONE', 'ダシテナイ人');
        $this->wants('S-OK', '稼働可');
        $this->wants('S-NG', 'NG');

        foreach (['S-OK', 'S-NG', 'S-NONE'] as $sid) {
            Application::create(['staff_id' => $sid, 'project_id' => $pid, 'intent' => '希望']);
        }

        $cases = $this->actingAsPerson($this->emp())->get('/entries')
            ->assertOk()->original->getData()['entriesCases'];
        $ents = collect($cases->firstWhere('id', $pid)['entrants'])->keyBy('id');

        $this->assertSame('ok', $ents['S-OK']['wish']);
        $this->assertSame('ng', $ents['S-NG']['wish'], '手は挙げたのにNG、という食い違いに気づけません。');
        $this->assertNull($ents['S-NONE']['wish'], '希望を出していない人まで〇／NGに見えてはいけません。');
    }

    /** 画面にも列と印が出ていること（消えたら誰も気づけない）。 */
    public function test_the_screen_shows_the_wish_column(): void
    {
        $this->actingAsPerson($this->emp())->get('/entries')
            ->assertOk()
            ->assertSee('その日の希望')
            ->assertSee('function wishTag', false)
            ->assertSee('e-wish ng', false);
    }

    /** カレンダーに、その日の終日〇の人が名前で並ぶこと。 */
    public function test_the_calendar_lists_who_is_free_on_each_day(): void
    {
        $this->staff('S-FREE', 'アイテル花子');
        $this->wants('S-FREE');

        $cal = $this->actingAsPerson($this->emp())->get('/entries')
            ->assertOk()->original->getData()['wishCalendar'];

        $key = $this->day->format('Y-m-d');
        $this->assertArrayHasKey($key, $cal, '終日〇の日がカレンダーに出ていません。');
        $this->assertSame('アイテル花子', $cal[$key][0]['name']);
        $this->assertSame('', $cal[$key][0]['st'], 'まだ何も決まっていない人は空の印になります。');
    }

    /** すでにアサイン済み・エントリー済みが印で分かること。 */
    public function test_the_calendar_marks_who_is_already_taken(): void
    {
        $pid = $this->project();
        $this->staff('S-ASG', 'アサインズミ太郎');
        $this->staff('S-ENT', 'エントリーズミ次郎');
        $this->staff('S-FREE', 'アイテル花子');
        foreach (['S-ASG', 'S-ENT', 'S-FREE'] as $sid) {
            $this->wants($sid);
        }
        Assignment::create([
            'project_id' => $pid, 'staff_id' => 'S-ASG', 'role' => 'FC',
            'date' => $this->day->format('Y-m-d'), 'status' => '仮',
        ]);
        Application::create(['staff_id' => 'S-ENT', 'project_id' => $pid, 'intent' => '希望']);

        $cal = $this->actingAsPerson($this->emp())->get('/entries')
            ->assertOk()->original->getData()['wishCalendar'];
        $day = collect($cal[$this->day->format('Y-m-d')])->keyBy('id');

        $this->assertSame('asg', $day['S-ASG']['st']);
        $this->assertSame('ent', $day['S-ENT']['st']);
        $this->assertSame('', $day['S-FREE']['st']);
        // まだ何も決まっていない人が先頭＝声を掛けられる人が上に来る。
        $this->assertSame('S-FREE', $cal[$this->day->format('Y-m-d')][0]['id']);
    }

    /**
     * カレンダーに出す人の決まりが、日別ボードの希望者カラムとそろっていること。
     * ⚠ ずれると「ボードには出るのにカレンダーには出ない」で混乱する。
     */
    public function test_the_calendar_uses_the_same_rule_as_the_day_board(): void
    {
        $this->staff('S-TKY', 'トウキョウ花子', '東京');
        $this->staff('S-OSA', 'オオサカ四郎', '大阪');
        $this->staff('S-OUT', 'タイショク三郎', '東京', false);
        PersonFactory::new()->create([
            'id' => 'E-SHA', 'name' => 'シャイン五郎', 'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
        foreach (['S-TKY', 'S-OSA', 'S-OUT', 'E-SHA'] as $sid) {
            $this->wants($sid);
        }

        // 一般社員＝自分の拠点だけ。
        $cal = $this->actingAsPerson($this->emp('employee'))->get('/entries')
            ->assertOk()->original->getData()['wishCalendar'];
        $ids = collect($cal[$this->day->format('Y-m-d')] ?? [])->pluck('id')->all();

        $this->assertContains('S-TKY', $ids);
        $this->assertNotContains('S-OSA', $ids, '他拠点の人が出ています。');
        $this->assertNotContains('S-OUT', $ids, '退職にした人が出ています。');
        $this->assertNotContains('E-SHA', $ids, '社員はふだんイベントに出ないので、ここには出しません。');
    }

    /** カレンダーの画面まわりが消えていないこと。 */
    public function test_the_calendar_tab_is_on_the_screen(): void
    {
        $this->actingAsPerson($this->emp())->get('/entries')
            ->assertOk()
            ->assertSee('📅 空いている人')
            ->assertSee('ECS_WISH_CAL', false)
            ->assertSee('function renderWishCal', false)
            ->assertSee('id="view-wishcal"', false);
    }
}
