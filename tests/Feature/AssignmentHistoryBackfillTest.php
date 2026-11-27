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

    /**
     * 取込でまとめて入ったぶん（入れた人が「Claude確認用」）だけを履歴から消す（小沼さん 11/27）。
     * 対象＝database/migrations/2026_11_27_000001_remove_import_rows_from_assignment_history.php
     */
    public function test_cleanup_removes_only_the_import_rows(): void
    {
        $project = ProjectFactory::new()->create();
        $row = fn (string $who, string $source, string $field = 'assignment') => ProjectHistory::create([
            'project_id' => $project->id, 'project_name' => 'テスト案件', 'action' => 'updated',
            'field' => $field, 'field_label' => 'アサイン：入れた', 'old_value' => '（なし）',
            'new_value' => 'だれか（MC・仮）', 'person_name' => $who, 'source' => $source,
        ]);
        $backfill = '後から起こした記録（11/25のぶん）';
        $before = ProjectHistory::count();   // 案件を作ったときの履歴がすでにあることがある
        $row('Claude確認用', $backfill);                              // 消す
        $row('Claude確認用', $backfill);                              // 消す
        $row('社員花子', $backfill);                                  // 同じ日に人が入れた＝残す
        $row('Claude確認用', '案件別アサイン › アサインの保存');      // あとの本物の履歴＝残す
        $row('Claude確認用', $backfill, 'project_name');              // アサイン以外＝残す

        $migration = require database_path('migrations/2026_11_27_000001_remove_import_rows_from_assignment_history.php');
        $migration->up();
        $migration->up();   // 2回流しても同じ

        $this->assertSame($before + 3, ProjectHistory::count());
        $this->assertSame(0, ProjectHistory::where('field', 'assignment')->where('source', $backfill)
            ->where('person_name', 'Claude確認用')->count());
        $this->assertSame(1, ProjectHistory::where('person_name', '社員花子')->count());
    }
}
