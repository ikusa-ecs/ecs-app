<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use App\Support\ConfirmedSchedule;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 出勤可能日（/employee-availability）に「もう決まっている案件」を出す（2026-08-28 baba要望）。
 *
 * 【背景】
 * 出勤可能日を書くとき、みんな手元の表に「この日はもう〇〇が入っている」と書き込む文化がある。
 * それを人が書き写さなくても ECS が自動で出す。
 *
 * ⚠ いちばん大事な決まり：**保存しない**。
 *   「その月の希望を出すとき、その案件があるかどうかまだ分からない」（baba）ので、
 *   希望を出した時点でコピーすると、あとから決まった案件が一生出てこない。
 *   だから開くたびに数え直す。このテストはそれを見張る。
 */
class AvailabilityConfirmedProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $id = 'E-101', string $name = '田中 健一'): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'role' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(string $id, string $date, array $extra = []): Project
    {
        return Project::create(array_merge([
            'id' => $id,
            'project_name' => '先が見えない防災訓練',
            'content_names' => ['先が見えない防災訓練'],
            'client' => 'コニカミノルタジャパン',
            'start_date' => $date,
            'event_start_time' => '15:40',
            'event_end_time' => '17:40',
            'office' => '東京',
            'status' => '確定',
        ], $extra));
    }

    private function assign(string $projectId, string $staffId, string $date, string $status = '確定', string $role = 'D'): Assignment
    {
        return Assignment::create([
            'project_id' => $projectId, 'staff_id' => $staffId,
            'date' => $date, 'role' => $role, 'status' => $status,
        ]);
    }

    /** 確定したアサインが、その日のところに出る。 */
    public function test_confirmed_assignment_shows_on_that_day(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(20);
        $this->project('P-1', $day->toDateString());
        $this->assign('P-1', $me->id, $day->toDateString());

        $got = ConfirmedSchedule::forPeople([$me->id]);
        $key = $day->year.'-'.$day->month.'-'.$day->day;

        $this->assertArrayHasKey($key, $got[$me->id]);
        $this->assertSame('P-1', $got[$me->id][$key][0]['id']);
        $this->assertSame('D（ディレクター）', $got[$me->id][$key][0]['roleLabel']);
        $this->assertSame('15:40-17:40', $got[$me->id][$key][0]['time']);
    }

    /**
     * ⚠ ここが要：**希望を出したあとにアサインが決まっても、次に開けば出る**。
     * 写しを保存していたら出てこない＝この動きが「保存しない」作りの証拠。
     */
    public function test_assignment_decided_after_the_wish_still_shows(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(25);
        $key = $day->year.'-'.$day->month.'-'.$day->day;

        // 先に希望だけ出す（この時点では案件はまだ無い）。
        ShiftPreference::create([
            'staff_id' => $me->id, 'period' => $day->format('Y-m'),
            'date' => $day->toDateString(), 'availability' => '稼働可',
        ]);
        $this->assertSame([], ConfirmedSchedule::forPeople([$me->id]));

        // あとから案件ができてアサインが確定した。
        $this->project('P-2', $day->toDateString());
        $this->assign('P-2', $me->id, $day->toDateString());

        $got = ConfirmedSchedule::forPeople([$me->id]);
        $this->assertSame('P-2', $got[$me->id][$key][0]['id'], '後から決まった案件が出ていない＝写しを保存してしまっている');
    }

    /** 仮のアサインは「決まっている」ではないので出さない。 */
    public function test_tentative_assignment_is_not_shown(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(10);
        $this->project('P-3', $day->toDateString());
        $this->assign('P-3', $me->id, $day->toDateString(), '仮');

        $this->assertSame([], ConfirmedSchedule::forPeople([$me->id]));
    }

    /** キャンセルの案件は出さない（やらないので）。 */
    public function test_cancelled_project_is_not_shown(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(11);
        $this->project('P-4', $day->toDateString(), ['is_cancelled' => true]);
        $this->assign('P-4', $me->id, $day->toDateString());

        $this->assertSame([], ConfirmedSchedule::forPeople([$me->id]));
    }

    /** 下書きの案件は出さない（まだ本決まりではない）。 */
    public function test_draft_project_is_not_shown(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(12);
        $this->project('P-5', $day->toDateString(), ['status' => '下書き']);
        $this->assign('P-5', $me->id, $day->toDateString());

        $this->assertSame([], ConfirmedSchedule::forPeople([$me->id]));
    }

    /** 2日ある案件は、2日とも出る（日はアサインの日で見る）。 */
    public function test_two_day_project_shows_on_both_days(): void
    {
        $me = $this->employee();
        $d1 = Carbon::today()->copy()->addDays(30);
        $d2 = $d1->copy()->addDay();
        $this->project('P-6', $d1->toDateString());
        $this->assign('P-6', $me->id, $d1->toDateString());
        $this->assign('P-6', $me->id, $d2->toDateString());

        $got = ConfirmedSchedule::forPeople([$me->id]);
        $this->assertCount(2, $got[$me->id]);
    }

    /** マスが狭いので、コンテンツ台帳に略称があればそれを使う。 */
    public function test_uses_the_content_short_name(): void
    {
        $me = $this->employee();
        Content::create(['id' => 'CT-1', 'content_name' => '先が見えない防災訓練', 'short_name' => '防災訓練']);
        $day = Carbon::today()->copy()->addDays(13);
        $this->project('P-7', $day->toDateString());
        $this->assign('P-7', $me->id, $day->toDateString());

        $got = ConfirmedSchedule::forPeople([$me->id]);
        $key = $day->year.'-'.$day->month.'-'.$day->day;
        $this->assertSame('防災訓練', $got[$me->id][$key][0]['name']);
    }

    /** 画面がその案件を受け取っていること（コントローラから渡っているか）。 */
    public function test_screen_receives_the_confirmed_projects(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(14);
        $this->project('P-8', $day->toDateString());
        $this->assign('P-8', $me->id, $day->toDateString());
        $key = $day->year.'-'.$day->month.'-'.$day->day;

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertViewHas('assigned', function ($assigned) use ($me, $key) {
                return isset($assigned[$me->id][$key]) && $assigned[$me->id][$key][0]['id'] === 'P-8';
            })
            // ⚠ Blade の @verbatim の切れ目を間違えると、画面は真っ白にならずに
            //   スクリプトの途中から消える（押しても動かないボタンになる＝気づけない）。
            //   新しい部分と、いちばん最後の関数の両方が残っているか見張る。
            ->assertSee('function applyExtras', false)
            ->assertSee('function openDayNote', false)
            ->assertSee('id="dnBack"', false)
            ->assertSee('function moveMonth', false);
    }

    /** その日のメモを保存・読み込みできる。 */
    public function test_day_note_can_be_saved_and_read_back(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(15);
        $key = $day->year.'-'.$day->month.'-'.$day->day;

        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id,
            'period' => $day->format('Y-m'),
            'state' => [$key => 'maybe'],
            'memo' => '今月は3件くらい入りたい',
            'day_notes' => [$key => '午後だけ可'],
        ])->assertOk()->assertJson(['ok' => true]);

        $row = ShiftPreference::where('staff_id', $me->id)->first();
        $this->assertSame('未定', $row->availability);
        $this->assertSame('午後だけ可', $row->day_note);
        $this->assertSame('今月は3件くらい入りたい', $row->note);

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertViewHas('prefs', function ($prefs) use ($me, $key) {
                return ($prefs[$me->id]['dayNote'][$key] ?? null) === '午後だけ可';
            });
    }

    /** 〇×△を付けずに、メモだけの日も保存できる。 */
    public function test_day_note_only_can_be_saved(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(16);
        $key = $day->year.'-'.$day->month.'-'.$day->day;

        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id,
            'period' => $day->format('Y-m'),
            'state' => [],
            'memo' => '',
            'day_notes' => [$key => '別件の打ち合わせあり'],
        ])->assertOk();

        $row = ShiftPreference::where('staff_id', $me->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->availability);
        $this->assertSame('別件の打ち合わせあり', $row->day_note);
    }

    /**
     * ⚠ 一度付けた〇を「未入力」に戻したら、保存でちゃんと消えること。
     * 2026-08-28 修正前は消えず、画面を開き直すと〇が復活していた。
     */
    public function test_clearing_a_mark_actually_removes_it(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(17);
        $key = $day->year.'-'.$day->month.'-'.$day->day;
        $period = $day->format('Y-m');

        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id, 'period' => $period, 'state' => [$key => 'ok'], 'memo' => '',
        ])->assertOk();
        $this->assertSame(1, ShiftPreference::where('staff_id', $me->id)->count());

        // 同じ月をもう一度保存。今度はその日を外している＝未入力に戻した。
        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id, 'period' => $period, 'state' => [], 'memo' => '',
        ])->assertOk();

        $this->assertSame(0, ShiftPreference::where('staff_id', $me->id)->count(), '〇を消したのに残っている');
    }

    /** ⚠ 消すのはその月だけ。開いていない月の入力まで消さないこと。 */
    public function test_clearing_does_not_touch_other_months(): void
    {
        $me = $this->employee();
        $thisMonth = Carbon::today()->copy()->startOfMonth()->addDays(9);
        $nextMonth = Carbon::today()->copy()->startOfMonth()->addMonth()->addDays(9);

        foreach ([$thisMonth, $nextMonth] as $d) {
            ShiftPreference::create([
                'staff_id' => $me->id, 'period' => $d->format('Y-m'),
                'date' => $d->toDateString(), 'availability' => '稼働可',
            ]);
        }

        // 今月ぶんだけ「全部未入力」で保存する。
        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id, 'period' => $thisMonth->format('Y-m'), 'state' => [], 'memo' => '',
        ])->assertOk();

        $this->assertSame(1, ShiftPreference::where('staff_id', $me->id)->count());
        $this->assertSame(
            $nextMonth->toDateString(),
            ShiftPreference::where('staff_id', $me->id)->first()->date->toDateString(),
            '開いていない月の入力まで消してしまっている'
        );
    }

    /** 〇×△を消しても、その日のメモが残っていれば行は残す。 */
    public function test_clearing_a_mark_keeps_the_day_note(): void
    {
        $me = $this->employee();
        $day = Carbon::today()->copy()->addDays(18);
        $key = $day->year.'-'.$day->month.'-'.$day->day;
        $period = $day->format('Y-m');

        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id, 'period' => $period,
            'state' => [$key => 'ok'], 'memo' => '', 'day_notes' => [$key => '前泊'],
        ])->assertOk();

        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $me->id, 'period' => $period, 'state' => [], 'memo' => '',
        ])->assertOk();

        $row = ShiftPreference::where('staff_id', $me->id)->first();
        $this->assertNotNull($row, 'メモが残っているのに行ごと消えてしまった');
        $this->assertNull($row->availability);
        $this->assertSame('前泊', $row->day_note);
    }

    /** 他人の確定案件も一覧タブ用に渡す（アサイン担当が空いている人を見るため）。 */
    public function test_other_employees_are_included(): void
    {
        $me = $this->employee();
        $other = $this->employee('E-102', '佐藤 花子');
        $day = Carbon::today()->copy()->addDays(19);
        $this->project('P-9', $day->toDateString());
        $this->assign('P-9', $other->id, $day->toDateString());

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertViewHas('assigned', fn ($a) => isset($a[$other->id]));
    }
}
