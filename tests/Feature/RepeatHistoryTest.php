<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Support\RepeatHistory;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * リピート案件の「前回」（2026-10-26 baba要望）。同じクライアントの、開催日がより前の案件のうち
 * いちばん近い、アサインが1人以上いる案件。
 */
class RepeatHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_is_the_nearest_earlier_case_with_assignments_for_the_same_client(): void
    {
        $person = PersonFactory::new()->create();
        $old = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-09-01', 'project_name' => '古い']);
        $prev = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-10-01', 'project_name' => '前回']);
        $repeat = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-11-01', 'is_repeat' => true, 'project_name' => '今回']);
        ProjectFactory::new()->create(['client' => '別社', 'start_date' => '2026-10-15', 'project_name' => '別の会社']);
        Assignment::create(['project_id' => $prev->id, 'staff_id' => $person->id, 'role' => 'OP', 'date' => '2026-10-01', 'status' => '確定']);
        Assignment::create(['project_id' => $old->id, 'staff_id' => $person->id, 'role' => 'OP', 'date' => '2026-09-01', 'status' => '確定']);

        $got = RepeatHistory::forProjects([$repeat]);

        $this->assertSame($prev->id, $got[(string) $repeat->id]['id']);
        $this->assertSame('10/1', $got[(string) $repeat->id]['date']);
        $this->assertSame([(string) $person->id], $got[(string) $repeat->id]['staffIds']);
    }

    /**
     * 何回目のリピートか・人ごとに過去何回入ったか（2026-11-25 小沼さん）。
     * 回数は月で数える（同じ月の2案件は1回）。前回に入っていない人も、前に入っていれば数える。
     */
    public function test_count_and_per_person_counts_cover_all_past_cases(): void
    {
        $always = PersonFactory::new()->create(['name' => 'いつも子']);
        $onlyOld = PersonFactory::new()->create(['name' => 'むかし子']);
        $never = PersonFactory::new()->create(['name' => 'はじめて子']);
        $mk = fn (string $d) => ProjectFactory::new()->create(['client' => 'A社', 'start_date' => $d]);
        $in = fn ($p, $who, string $d) => Assignment::create(['project_id' => $p->id, 'staff_id' => $who->id, 'role' => 'FC', 'date' => $d, 'status' => '確定']);
        // 過去＝5月（2案件・同じ月）・9月・10月 → 今回（11月）は4回目
        $may1 = $mk('2026-05-10'); $may2 = $mk('2026-05-20'); $sep = $mk('2026-09-01'); $oct = $mk('2026-10-01');
        $now = $mk('2026-11-01');
        $in($may1, $always, '2026-05-10'); $in($may2, $always, '2026-05-20'); $in($may1, $onlyOld, '2026-05-10');
        $in($sep, $always, '2026-09-01'); $in($sep, $onlyOld, '2026-09-01');
        $in($oct, $always, '2026-10-01');

        $got = RepeatHistory::forProjects([$now])[(string) $now->id];

        $this->assertSame(4, $got['count']);
        $this->assertSame([(string) $always->id], $got['staffIds'], '前回（10月）に入ったのは1人だけ');
        $this->assertSame(3, $got['staffCounts'][(string) $always->id], '5月は2案件でも1回');
        $this->assertSame(2, $got['staffCounts'][(string) $onlyOld->id], '前回に入っていなくても数える');
        $this->assertArrayNotHasKey((string) $never->id, $got['staffCounts']);
        $this->assertSame(['いつも子' => 3, 'むかし子' => 2], $got['nameCounts']);
        // スタッフの画面などに使う「名前なし」のときは、名前の表を作らない
        $this->assertSame([], RepeatHistory::forProjects([$now], false)[(string) $now->id]['nameCounts']);
    }

    /**
     * おすすめの点数は上乗せ制（2026-11-25 小沼さん）：入った回数1回につき+10（上限+40）・前回も入った人はさらに+20。
     * 前回だけ入った人は 10+20＝30 で、今までと同じ。
     */
    public function test_score_stacks_by_past_count(): void
    {
        $p = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-11-01']);
        $people = [];
        foreach (['none', 'prev1', 'prev3', 'old2', 'prev9'] as $k) {
            $people[$k] = PersonFactory::new()->staff()->create();
        }
        $prevIds = [(string) $people['prev1']->id, (string) $people['prev3']->id, (string) $people['prev9']->id];
        $counts = [(string) $people['prev1']->id => 1, (string) $people['prev3']->id => 3, (string) $people['old2']->id => 2, (string) $people['prev9']->id => 9];
        $score = function (string $k, bool $withCounts = true) use ($p, $people, $prevIds, $counts) {
            $s = new \App\Support\AssignmentScorer($p, \Illuminate\Support\Carbon::parse('2026-11-01'), [], [], [], $prevIds);
            if ($withCounts) {
                $s->setRepeatCounts($counts);
            }

            return $s->evaluate($people[$k]);
        };
        $base = $score('none')['score'];

        $this->assertEquals(30, $score('prev1')['score'] - $base, '前回だけ＝今までと同じ+30');
        $this->assertEquals(50, $score('prev3')['score'] - $base, '3回×10＋前回20');
        $this->assertEquals(20, $score('old2')['score'] - $base, '前回は入っていなくても2回×10');
        $this->assertEquals(60, $score('prev9')['score'] - $base, '回数ぶんは+40まで');
        $this->assertEquals(30, $score('prev3', false)['score'] - $base, '回数を渡さない呼び出しは前回＝1回として数える');
        $this->assertContains('このお客様の案件に過去2回参加', $score('old2')['reasons']);
    }

    /**
     * だれがD・MCだったか（2026-11-25 小沼さん）。先頭が前回。Dはアサインが正、無ければ案件の古い列。
     * スタッフの画面用（名前なし）には渡さない。
     */
    public function test_role_history_tells_who_was_d_and_mc(): void
    {
        $d1 = PersonFactory::new()->create(['name' => 'ディー太']);
        $d2 = PersonFactory::new()->create(['name' => 'むかしディー']);
        $mc = PersonFactory::new()->create(['name' => 'エムシー子']);
        $op = PersonFactory::new()->create(['name' => 'オペ男']);
        $sep = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-09-01', 'director_id' => $d2->id]);
        $oct = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-10-01']);
        $now = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-11-01']);
        $in = fn ($p, $who, string $role, string $d) => Assignment::create(['project_id' => $p->id, 'staff_id' => $who->id, 'role' => $role, 'date' => $d, 'status' => '確定']);
        $in($oct, $d1, 'D', '2026-10-01'); $in($oct, $mc, 'MC', '2026-10-01'); $in($oct, $op, 'OP', '2026-10-01');
        $in($sep, $op, 'OP', '2026-09-01');   // 9月はDのアサインが無い → 案件の古い列から

        $got = RepeatHistory::forProjects([$now])[(string) $now->id];

        $this->assertSame([
            ['date' => '10/1', 'D' => ['ディー太'], 'MC' => ['エムシー子']],
            ['date' => '9/1', 'D' => ['むかしディー'], 'MC' => []],
        ], $got['roleHistory']);
        $this->assertSame([], RepeatHistory::forProjects([$now], false)[(string) $now->id]['roleHistory']);
    }

    /**
     * スタッフの画面の「🔁 リピート案件」は、本人がそのお客様の案件に入ったことがあるときだけ（2026-11-25 小沼さん）。
     * 前回・前々回は問わない。入ったことがない人には出さない。
     */
    public function test_staff_portal_shows_repeat_only_to_people_who_joined_before(): void
    {
        $day = \Illuminate\Support\Carbon::today()->addDays(10);
        $old = ProjectFactory::new()->published()->create(['office' => '東京', 'client' => 'リピート商事', 'start_date' => $day->copy()->subMonths(5)->format('Y-m-d')]);
        $prev = ProjectFactory::new()->published()->create(['office' => '東京', 'client' => 'リピート商事', 'start_date' => $day->copy()->subMonths(2)->format('Y-m-d')]);
        ProjectFactory::new()->published()->create(['office' => '東京', 'client' => 'リピート商事', 'start_date' => $day->format('Y-m-d'), 'is_repeat' => true]);
        $joinedOld = PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);
        $other = PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);
        $never = PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);
        Assignment::create(['project_id' => $old->id, 'staff_id' => $joinedOld->id, 'role' => 'OP', 'date' => $old->start_date->format('Y-m-d'), 'status' => '確定']);
        Assignment::create(['project_id' => $prev->id, 'staff_id' => $other->id, 'role' => 'OP', 'date' => $prev->start_date->format('Y-m-d'), 'status' => '確定']);

        $a = $this->actingAsPerson($joinedOld)->get('/staff-portal')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/"repeatPrev":\{"date"/', $a, '前回は入っていなくても、前に入った人には出る');

        $b = $this->actingAsPerson($never)->get('/staff-portal')->assertOk()->getContent();
        $this->assertStringNotContainsString('"repeatPrev":{', $b, '入ったことがない人には出さない');
        $this->assertStringNotContainsString('"repeat":true', $b, '「リピート案件」のチェックだけでは出さない');
    }

    public function test_no_entry_when_no_previous(): void
    {
        $plain = ProjectFactory::new()->create(['client' => 'B社', 'start_date' => '2026-11-01', 'is_repeat' => false]);
        $alone = ProjectFactory::new()->create(['client' => 'C社', 'start_date' => '2026-11-01', 'is_repeat' => true]);

        $this->assertSame([], RepeatHistory::forProjects([$plain, $alone]));
    }

    /** 「リピート案件」のチェックが無くても、前回があれば拾う（2026-11-16 小沼さん）。年がちがえば年も出す。 */
    public function test_previous_is_found_without_the_repeat_check_and_shows_year_when_different(): void
    {
        $person = PersonFactory::new()->create();
        $prev = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2025-12-05', 'is_repeat' => false]);
        $now = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-12-04', 'is_repeat' => false]);
        Assignment::create(['project_id' => $prev->id, 'staff_id' => $person->id, 'role' => 'OP', 'date' => '2025-12-05', 'status' => '確定']);

        $got = RepeatHistory::forProjects([$now]);

        $this->assertSame('2025/12/5', $got[(string) $now->id]['date']);
        $this->assertSame([(string) $person->id], RepeatHistory::staffIdsFor($now));
    }

    /**
     * 画面に届いているか：日別ボードに前回（人つき）、エントリー一覧に「前回も入った人」の印。
     * ⚠ 日別ボードは以前、組み立ての中へ渡し忘れて**エラーにならずに空**だった（2026-11-16 発見）。ここで見張る。
     */
    public function test_day_board_and_entries_carry_the_previous_members(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $staff = PersonFactory::new()->staff()->create(['name' => '前回の人', 'office' => '東京']);
        $day = \Illuminate\Support\Carbon::today()->addDays(5);
        $prevDay = $day->copy()->subYear();
        $prev = ProjectFactory::new()->create(['office' => '東京', 'client' => 'リピート商事', 'start_date' => $prevDay->format('Y-m-d')]);
        $now = ProjectFactory::new()->create(['office' => '東京', 'client' => 'リピート商事', 'start_date' => $day->format('Y-m-d')]);
        Assignment::create(['project_id' => $prev->id, 'staff_id' => $staff->id, 'role' => 'OP', 'date' => $prevDay->format('Y-m-d'), 'status' => '確定']);
        \App\Models\Application::create(['project_id' => $now->id, 'staff_id' => $staff->id, 'intent' => '希望']);

        $board = $this->actingAsPerson($emp)->get('/assign')->assertOk()->getContent();
        $this->assertStringContainsString('"staffNames":["前回の人"]', $this->unescapeJson($board),'日別ボードに前回の人が届いている');
        $this->assertStringContainsString('function prevMarkHtml(c, p)', $board);

        $entries = $this->actingAsPerson($emp)->get('/entries')->assertOk()->getContent();
        $this->assertStringContainsString('"prev":true', $entries, 'エントリー一覧：前回も入った人');
        $this->assertStringContainsString('function prevTag(c, e)', $entries);
        // 前回のD・MC（2026-11-25 小沼さん）：エントリー一覧とスタッフ公開ボードにも届いている。
        $this->assertStringContainsString('"repeatRoles":{"prev":"D ', $entries);
        $publish = $this->actingAsPerson($emp)->get('/assign-publish')->assertOk()->getContent();
        $this->assertStringContainsString('repeatRoles: c.repeatRoles', $publish);
        // スタッフは公開ボードを開けない（名前を渡す画面なので見張る）。
        $this->actingAsPerson($staff)->get('/assign-publish')->assertRedirect();
    }

    /** @json は日本語を \uXXXX にするので、読める形に戻す。 */
    private function unescapeJson(string $html): string
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn ($m) => mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UTF-16BE'), $html);
    }

    /** 同じ月の案件・連日イベントの別の日・名前が決まっていないお客様は「前回」にしない。 */
    public function test_same_month_same_series_and_unknown_client_are_not_previous(): void
    {
        $person = PersonFactory::new()->create();
        $mk = function (array $attrs) use ($person) {
            $p = ProjectFactory::new()->create($attrs);
            Assignment::create(['project_id' => $p->id, 'staff_id' => $person->id, 'role' => 'OP', 'date' => $attrs['start_date'], 'status' => '確定']);

            return $p;
        };

        // 同じ月（11/28 と 11/29）
        $mk(['client' => 'A社', 'start_date' => '2026-11-28']);
        $sameMonth = ProjectFactory::new()->create(['client' => 'A社', 'start_date' => '2026-11-29']);

        // 連日イベント（月をまたぐ 10/31 と 11/1）
        $day1 = $mk(['client' => 'B社', 'start_date' => '2026-10-31']);
        $day2 = ProjectFactory::new()->create(['client' => 'B社', 'start_date' => '2026-11-01', 'parent_project_id' => $day1->id]);

        // 名前が決まっていないお客様
        $mk(['client' => '顧客名なし', 'start_date' => '2026-09-01']);
        $unknown = ProjectFactory::new()->create(['client' => '顧客名なし', 'start_date' => '2026-11-01']);

        $this->assertSame([], RepeatHistory::forProjects([$sameMonth, $day2, $unknown]));
    }
}
