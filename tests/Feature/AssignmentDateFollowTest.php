<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 開催日を変えたら、アサインの日付も一緒に移る（2026-10-09 baba了承・正本=App\Support\AssignmentDateFollow）。
 * ⚠ 移すのは古い開催日の行だけ。別の日（予備日・リハなど）の行には触らない。
 */
class AssignmentDateFollowTest extends TestCase
{
    use RefreshDatabase;

    private function assign(string $pid, string $staff, string $date, string $role = 'D'): void
    {
        Assignment::create([
            'project_id' => $pid, 'staff_id' => $staff, 'role' => $role,
            'date' => $date, 'status' => '確定',
        ]);
    }

    private function dates(string $pid, string $staff): array
    {
        return Assignment::where('project_id', $pid)->where('staff_id', $staff)->get()
            ->map(fn ($a) => $a->date->toDateString())->sort()->values()->all();
    }

    public function test_開催日を変えるとアサインも新しい日へ移る(): void
    {
        PersonFactory::new()->create(['id' => 'E-1']);
        PersonFactory::new()->create(['id' => 'S-1']);
        ProjectFactory::new()->create(['id' => 'P-1', 'start_date' => '2026-11-03']);
        $this->assign('P-1', 'E-1', '2026-11-03', 'D');
        $this->assign('P-1', 'S-1', '2026-11-03', 'OP');
        $this->assign('P-1', 'S-1', '2026-11-02', 'OP');   // 前日設営など別の日＝動かさない

        $p = Project::find('P-1');
        $p->start_date = '2026-11-10';
        $p->save();

        $this->assertSame(['2026-11-10'], $this->dates('P-1', 'E-1'));
        $this->assertSame(['2026-11-02', '2026-11-10'], $this->dates('P-1', 'S-1'));
        $this->assertSame('確定', Assignment::where('staff_id', 'E-1')->first()->status);
    }

    public function test_新しい日にもう同じ人がいれば古い行は消す(): void
    {
        PersonFactory::new()->create(['id' => 'E-1']);
        ProjectFactory::new()->create(['id' => 'P-1', 'start_date' => '2026-11-03']);
        $this->assign('P-1', 'E-1', '2026-11-03');
        $this->assign('P-1', 'E-1', '2026-11-10');

        $p = Project::find('P-1');
        $p->start_date = '2026-11-10';
        $p->save();

        $this->assertSame(['2026-11-10'], $this->dates('P-1', 'E-1'));
    }

    public function test_開催日以外を直してもアサインは動かない(): void
    {
        PersonFactory::new()->create(['id' => 'E-1']);
        ProjectFactory::new()->create(['id' => 'P-1', 'start_date' => '2026-11-03']);
        $this->assign('P-1', 'E-1', '2026-11-05');

        $p = Project::find('P-1');
        $p->note = 'メモ';
        $p->save();

        $this->assertSame(['2026-11-05'], $this->dates('P-1', 'E-1'));
    }

    public function test_ロゴのカメを空に戻す(): void
    {
        ProjectFactory::new()->create(['id' => 'P-1', 'pub_logo' => 'カメ']);
        ProjectFactory::new()->create(['id' => 'P-2', 'pub_logo' => 'OK']);

        $m = require database_path('migrations/2026_10_09_000001_clear_logo_kame.php');
        $m->up();

        $this->assertNull(DB::table('projects')->where('id', 'P-1')->value('pub_logo'));
        $this->assertSame('OK', DB::table('projects')->where('id', 'P-2')->value('pub_logo'));
    }
}
