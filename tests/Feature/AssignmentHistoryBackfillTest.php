<?php

namespace Tests\Feature;

use App\Models\ProjectHistory;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 11/25 のアサインを編集履歴に後から起こす処理（小沼さん 11/25「今日だけでいいから出して」）。
 * 対象＝database/migrations/2026_11_25_000001_backfill_assignment_history_for_today.php
 */
class AssignmentHistoryBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_writes_todays_assignments_once(): void
    {
        $employee = PersonFactory::new()->create(['name' => '社員花子']);
        $project  = ProjectFactory::new()->create(['start_date' => '2026-12-01']);
        $a = PersonFactory::new()->staff()->create(['name' => '今日入れた']);
        $b = PersonFactory::new()->staff()->create(['name' => '今日確定した']);
        $c = PersonFactory::new()->staff()->create(['name' => '前から居る']);

        $row = fn ($p, array $more) => DB::table('assignments')->insert($more + [
            'project_id' => $project->id, 'staff_id' => $p->id, 'date' => '2026-12-01',
            'role' => 'MC', 'status' => '仮', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row($a, ['assigned_by' => $employee->id, 'assigned_at' => '2026-11-25 09:10:00']);
        $row($b, ['status' => '確定', 'assigned_by' => $employee->id, 'assigned_at' => '2026-11-20 10:00:00',
            'confirmed_by' => $employee->id, 'confirmed_at' => '2026-11-25 11:00:00']);
        $row($c, ['assigned_by' => $employee->id, 'assigned_at' => '2026-11-20 10:00:00']);

        // ⚠ テスト用のDBを作るときに1回流れている（そのときは行が無い＝何もしない）。ここでもう一度流す。
        $migration = require database_path('migrations/2026_11_25_000001_backfill_assignment_history_for_today.php');
        $migration->up();
        $migration->up();   // 2回流しても増えない

        $rows = ProjectHistory::where('field', 'assignment')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('アサイン：入れた', $rows[0]->field_label);
        $this->assertSame('今日入れた（MC・仮）', $rows[0]->new_value);
        $this->assertSame('社員花子', $rows[0]->person_name);
        $this->assertSame('2026-11-25 09:10', $rows[0]->created_at->format('Y-m-d H:i'));
        $this->assertSame('アサイン：変更', $rows[1]->field_label);
        $this->assertSame('今日確定した（MC・確定）', $rows[1]->new_value);
    }
}
