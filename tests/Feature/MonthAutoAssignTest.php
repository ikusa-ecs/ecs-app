<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AutoAssignRun;
use App\Models\ShiftPreference;
use App\Support\MonthAutoAssign;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 月まとめの自動アサイン（/auto-assign-month）を守るテスト（2026-09-07 baba要望）。
 *
 * 【この機能のいちばん大事な約束】
 *  ① プレビューは **DBを一切書き換えない**（何が起きるか先に全部見せる）
 *  ② 入るのは全部「仮」
 *  ③ **取り消せる**（1回ぶんに番号を振る）。ただし人が「確定」に上げたものは残す
 *  ④ 同じ人が同じ日に2つの案件へ入らない
 *  ⑤ 締めた案件・NGの人・今月上限には触らない
 */
class MonthAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    private function wish($staff, Carbon $day): void
    {
        ShiftPreference::create([
            'staff_id' => $staff->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);
    }

    private function ng($staff, Carbon $day): void
    {
        ShiftPreference::create([
            'staff_id' => $staff->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => 'NG',
        ]);
    }

    /** 〇を出している人が、足りない案件に入る計画になる。 */
    public function test_plan_fills_a_short_project(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);
        $a = PersonFactory::new()->staff()->create(['name' => 'あ子', 'office' => '東京']);
        $b = PersonFactory::new()->staff()->create(['name' => 'い子', 'office' => '東京']);
        $this->wish($a, $day);
        $this->wish($b, $day);

        $plan = (new MonthAutoAssign($day->format('Y-m')))->plan();

        $this->assertSame(1, $plan['totals']['projects']);
        $this->assertSame(2, $plan['totals']['added']);
        $this->assertSame(0, $plan['totals']['stillShort']);
        // ⚠ プレビューはDBを書き換えない。
        $this->assertSame(0, Assignment::count());
    }

    /** ⚠ その日がNGの人は入れない。 */
    public function test_ng_staff_is_not_picked(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);
        $ng = PersonFactory::new()->staff()->create(['name' => 'NG子', 'office' => '東京']);
        $this->ng($ng, $day);

        $plan = (new MonthAutoAssign($day->format('Y-m')))->plan();

        $this->assertSame(0, $plan['totals']['added']);
    }

    /** ⚠ 同じ人が同じ日に2つの案件へ入らない。 */
    public function test_no_double_booking_on_the_same_day(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->count(2)->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);
        $only = PersonFactory::new()->staff()->create(['name' => '一人だけ', 'office' => '東京']);
        $this->wish($only, $day);

        $plan = (new MonthAutoAssign($day->format('Y-m')))->plan();

        $this->assertSame(1, $plan['totals']['added'], '1件にしか入らない');
        $this->assertSame(1, $plan['totals']['stillShort'], 'もう1件は足りないまま');
    }

    /** ⚠ 「🔒 この人数で足りている」で締めた案件（公開ずみ・募集オフ）には触らない。 */
    public function test_settled_project_is_skipped(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 3,
            'staff_published' => true, 'is_recruiting' => false, 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);
        $this->wish($s, $day);

        $plan = (new MonthAutoAssign($day->format('Y-m')))->plan();

        $this->assertSame(0, $plan['totals']['projects']);
    }

    /** ⚠ 運営人数が未入力（0）の案件は対象外（何人必要か決まっていない）。 */
    public function test_project_without_required_count_is_skipped(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 0, 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);
        $this->wish($s, $day);

        $plan = (new MonthAutoAssign($day->format('Y-m')))->plan();

        $this->assertSame(0, $plan['totals']['projects']);
    }

    /**
     * ⚠ 取り合いが厳しい案件から先に埋める。
     * 候補が1人しかいない案件を後回しにすると、その案件は埋まらない。
     */
    public function test_the_tight_project_is_filled_first(): void
    {
        $month = Carbon::today()->startOfMonth();
        $day1 = $month->copy()->addDays(10);   // 候補が多い日
        $day2 = $month->copy()->addDays(20);   // 候補が1人だけの日

        $easy = ProjectFactory::new()->create([
            'start_date' => $day1->format('Y-m-d'), 'required_count' => 1,
            'project_name' => 'ゆとりのある案件', 'office' => '東京',
        ]);
        $tight = ProjectFactory::new()->create([
            'start_date' => $day2->format('Y-m-d'), 'required_count' => 1,
            'project_name' => 'きびしい案件', 'office' => '東京',
        ]);

        $many = PersonFactory::new()->staff()->count(3)->create(['office' => '東京']);
        foreach ($many as $m) {
            $this->wish($m, $day1);
        }
        $rare = PersonFactory::new()->staff()->create(['name' => '希少さん', 'office' => '東京']);
        $this->wish($rare, $day2);

        $plan = (new MonthAutoAssign($month->format('Y-m')))->plan();

        $this->assertSame('きびしい案件', $plan['projects'][0]['name'], '候補が少ない案件が先');
        $this->assertSame(2, $plan['totals']['added'], '両方とも埋まる');
        $this->assertSame($tight->id, $plan['projects'][0]['id']);
        $this->assertNotNull(collect($plan['projects'])->firstWhere('id', $easy->id));
    }

    /** 実行すると「仮」で保存され、番号（auto_run_id）が付く。 */
    public function test_run_saves_as_tentative_with_a_run_id(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);
        $this->wish($s, $day);

        $this->actingAsPerson($me)
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m')])
            ->assertRedirect();

        $a = Assignment::first();
        $this->assertNotNull($a);
        $this->assertSame('仮', $a->status);
        $this->assertNotNull($a->auto_run_id);
        $this->assertSame(1, AutoAssignRun::first()->added);
    }

    /**
     * ⚠ 取り消しは「その回の仮」だけ。人が「確定」に上げたものは残す
     * ＝人の判断を機械が消さない。
     */
    public function test_undo_removes_only_the_tentative_ones(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);
        PersonFactory::new()->staff()->count(2)->create(['office' => '東京'])
            ->each(fn ($s) => $this->wish($s, $day));

        $this->actingAsPerson($me)->post('/auto-assign-month/run', ['period' => $day->format('Y-m')]);
        $this->assertSame(2, Assignment::count());

        // 1人だけ人が「確定」に上げた。
        $kept = Assignment::first();
        $kept->update(['status' => '確定']);

        $run = AutoAssignRun::first();
        $this->actingAsPerson($me)
            ->post('/auto-assign-month/undo', ['run_id' => $run->id])
            ->assertRedirect();

        $this->assertSame(1, Assignment::count(), '確定にしたものは残る');
        $this->assertSame('確定', Assignment::first()->status);
        $this->assertSame(1, $run->fresh()->undone);
    }

    /** 一般社員は実行できない（まとめて動く操作は管理者以上）。 */
    public function test_a_normal_employee_cannot_run_it(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'employee', 'office' => '東京']);
        $day = Carbon::today()->startOfMonth()->addDays(10);

        $this->actingAsPerson($emp)
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m')])
            ->assertForbidden();
    }

    /** 画面が開けて、下見であることが書いてある。 */
    public function test_the_screen_opens(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);

        $this->actingAsPerson($me)->get('/auto-assign-month')
            ->assertOk()
            ->assertSee('この画面を開いただけでは、何も保存されていません', false);
    }

    /**
     * ⚠「この日はアサインしない」で外した日は、計画に**出さない**（2026-09-07 baba要望）。
     * 下見に出ると「入るもの」と勘違いする。実行のときも同じ日を外す。
     */
    public function test_skipped_day_is_excluded(): void
    {
        $month = Carbon::today()->startOfMonth();
        $day1 = $month->copy()->addDays(10);
        $day2 = $month->copy()->addDays(11);
        ProjectFactory::new()->create([
            'start_date' => $day1->format('Y-m-d'), 'required_count' => 1,
            'project_name' => '外す日の案件', 'office' => '東京',
        ]);
        ProjectFactory::new()->create([
            'start_date' => $day2->format('Y-m-d'), 'required_count' => 1,
            'project_name' => '残す日の案件', 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->count(2)->create(['office' => '東京']);
        foreach ($s as $one) {
            $this->wish($one, $day1);
            $this->wish($one, $day2);
        }

        $plan = (new MonthAutoAssign($month->format('Y-m'), null, [$day1->format('Y-m-d')]))->plan();

        $names = collect($plan['projects'])->pluck('name')->all();
        $this->assertNotContains('外す日の案件', $names);
        $this->assertContains('残す日の案件', $names);
    }

    /** ⚠ 外した日も含めて日の一覧を返す（外さないと、チェックを戻せなくなる）。 */
    public function test_candidate_days_include_skipped_ones(): void
    {
        $month = Carbon::today()->startOfMonth();
        $day = $month->copy()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);

        $engine = new MonthAutoAssign($month->format('Y-m'), null, [$day->format('Y-m-d')]);

        $this->assertArrayHasKey($day->format('Y-m-d'), $engine->candidateDays());
        $this->assertSame(0, $engine->plan()['totals']['projects']);
    }

    /** ⚠ 実行のときも外した日は入れない（プレビューで外したのに入る、では意味がない）。 */
    public function test_run_respects_skipped_days(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);
        $this->wish($s, $day);

        $this->actingAsPerson($me)->post('/auto-assign-month/run', [
            'period' => $day->format('Y-m'),
            'skip' => [$day->format('Y-m-d')],
        ])->assertRedirect();

        $this->assertSame(0, Assignment::count());
    }

    /**
     * ⚠「この案件は入れない」で外した案件は、計画に出さない（2026-09-07 baba要望）。
     * 実行のときも同じものを外す。
     */
    public function test_skipped_project_is_excluded(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $skip = ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1,
            'project_name' => '外す案件', 'office' => '東京',
        ]);
        $keep = ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1,
            'project_name' => '残す案件', 'office' => '東京',
        ]);
        PersonFactory::new()->staff()->count(2)->create(['office' => '東京'])
            ->each(fn ($s) => $this->wish($s, $day));

        $plan = (new MonthAutoAssign($day->format('Y-m'), null, [], [$skip->id]))->plan();
        $names = collect($plan['projects'])->pluck('name')->all();
        $this->assertNotContains('外す案件', $names);
        $this->assertContains('残す案件', $names);

        // 実行でも外れる。
        $this->actingAsPerson($me)->post('/auto-assign-month/run', [
            'period' => $day->format('Y-m'),
            'skipProject' => [$skip->id],
        ])->assertRedirect();

        $this->assertSame(0, Assignment::where('project_id', $skip->id)->count());
        $this->assertSame(1, Assignment::where('project_id', $keep->id)->count());
    }

    /** ⚠ 外した案件も一覧には返す（返さないとチェックを戻せなくなる）。 */
    public function test_candidate_projects_include_skipped_ones(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);

        $engine = new MonthAutoAssign($day->format('Y-m'), null, [], [$p->id]);

        $this->assertContains($p->id, collect($engine->candidateProjects())->pluck('id')->all());
        $this->assertSame(0, $engine->plan()['totals']['projects']);
    }

    /**
     * ⚠ 表示は日ごとにしたが、**埋める順は変えていない**。
     * その証拠として、各行に「何番目に埋めたか」（order）が入っていること。
     */
    public function test_each_row_keeps_the_fill_order(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $month = Carbon::today()->startOfMonth();
        $day1 = $month->copy()->addDays(20);   // 候補が多い日（あとで埋まる）
        $day2 = $month->copy()->addDays(10);   // 候補が1人だけの日（先に埋まる）

        ProjectFactory::new()->create([
            'start_date' => $day1->format('Y-m-d'), 'required_count' => 1,
            'project_name' => 'ゆとり', 'office' => '東京',
        ]);
        ProjectFactory::new()->create([
            'start_date' => $day2->format('Y-m-d'), 'required_count' => 1,
            'project_name' => 'きびしい', 'office' => '東京',
        ]);
        PersonFactory::new()->staff()->count(3)->create(['office' => '東京'])
            ->each(fn ($s) => $this->wish($s, $day1));
        $this->wish(PersonFactory::new()->staff()->create(['office' => '東京']), $day2);

        $data = $this->actingAsPerson($me)
            ->get('/auto-assign-month?period=' . $month->format('Y-m'))
            ->assertOk()->original->getData();

        $byName = collect($data['plan']['projects'])->keyBy('name');
        $this->assertSame(1, $byName['きびしい']['order'], '候補が少ない案件が1番目');
        $this->assertSame(2, $byName['ゆとり']['order']);

        // 表示は日ごと（日付をキーにまとまっている・日付順）。
        $this->assertSame(
            [$day2->format('Y-m-d'), $day1->format('Y-m-d')],
            array_keys($data['byDay']),
            '見出しは日付の早い順'
        );
    }

    /**
     * ⚠ 希望数の数え方は「スタッフ一覧」と**同じ**であること（2026-09-07 baba指摘）。
     *
     * はじめ2か所で別々に数えていた（スタッフ一覧＝入れる枠の数／月まとめ＝〇の日数だけ）。
     * 同じ「希望数」「充足率」という言葉なのに画面で数が違う＝どちらが正しいのか分からなくなる。
     * 正本＝[[App\Support\WishCount]]。ここで数え直さないこと。
     */
    public function test_wish_count_matches_the_staff_list(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $month = Carbon::today()->startOfMonth();
        $day = $month->copy()->addDays(10);

        // 同じ日に案件を3件つくる＝〇1日ぶんが「3枠」と数えられる。
        $ps = ProjectFactory::new()->count(3)->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->create(['name' => '数え方さん', 'office' => '東京']);
        $this->wish($s, $day);
        \App\Models\Application::create([
            'project_id' => $ps->first()->id, 'staff_id' => $s->id, 'intent' => '希望',
        ]);

        // 正本の数え方＝〇1日 × その日の案件3件 ＝ 3（エントリーは同じ日なので足さない）。
        $expected = \App\Support\WishCount::forMonth($month->format('Y-m'))[$s->id];
        $this->assertSame(3, $expected['wish']);
        $this->assertSame(1, $expected['okDays']);
        $this->assertSame(1, $expected['entries']);

        // 月まとめの下見も同じ数を使っている。
        $data = $this->actingAsPerson($me)
            ->get('/auto-assign-month?period=' . $month->format('Y-m'))
            ->assertOk()->original->getData();
        $row = collect($data['plan']['staff'])->firstWhere('name', '数え方さん');
        $this->assertSame(3, $row['wishDays']);
        $this->assertSame(1, $row['okDays']);
        $this->assertSame(1, $row['entries']);

        // スタッフ一覧も同じ数。
        $wl = $this->actingAsPerson($me)
            ->get('/assign-wishlist?period=' . $month->format('Y-m'))
            ->assertOk()->original->getData();
        $wlRow = collect($wl['people'])->firstWhere('name', '数え方さん');
        $this->assertSame(3, $wlRow['wish']);
        $this->assertSame(1, $wlRow['entries']);
    }
}
