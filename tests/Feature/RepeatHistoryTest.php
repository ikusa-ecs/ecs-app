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
