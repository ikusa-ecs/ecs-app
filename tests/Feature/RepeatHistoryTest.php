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

    public function test_no_entry_when_not_repeat_or_no_previous(): void
    {
        $plain = ProjectFactory::new()->create(['client' => 'B社', 'start_date' => '2026-11-01', 'is_repeat' => false]);
        $alone = ProjectFactory::new()->create(['client' => 'C社', 'start_date' => '2026-11-01', 'is_repeat' => true]);

        $this->assertSame([], RepeatHistory::forProjects([$plain, $alone]));
    }
}
