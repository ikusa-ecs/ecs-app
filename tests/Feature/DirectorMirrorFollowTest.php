<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectHistory;
use App\Support\DirectorSync;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D／SD の「写し」（projects.director_id / sd_id）が、どの画面からアサインを変えても追いかける。
 * 2026-11-12 小沼さん「アサイン表だけ、外したはずの水内さんがDに出ている」。
 *
 * 原因＝D決め・案件一覧のセル以外（日別ボード・取込など）でDのアサインを外すと写しが残った。
 * アサイン表と案件一覧は写しを読むので、古いDが出続けた。正本＝DirectorSync::queueRefresh。
 */
class DirectorMirrorFollowTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $projectId, string $staffId, string $role): Assignment
    {
        return Assignment::create([
            'project_id' => $projectId, 'staff_id' => $staffId,
            'date' => '2027-03-01', 'role' => $role, 'status' => '仮',
        ]);
    }

    public function test_Dのアサインを入れる・外すと写しも変わる(): void
    {
        $d = PersonFactory::new()->create();
        $sd = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => '2027-03-01']);

        $rowD = $this->row($p->id, $d->id, 'D');
        $this->row($p->id, $sd->id, 'SD');
        $this->assertSame($d->id, $p->fresh()->director_id);
        $this->assertSame($sd->id, $p->fresh()->sd_id);

        $rowD->delete();
        $this->assertNull($p->fresh()->director_id, 'Dを外したのに写しが残っている');
        $this->assertSame($sd->id, $p->fresh()->sd_id);
    }

    public function test_役割をDから別のものに変えると写しが消える(): void
    {
        $d = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => '2027-03-01']);
        $row = $this->row($p->id, $d->id, 'D');

        $row->update(['role' => 'FC']);

        $this->assertNull($p->fresh()->director_id);
    }

    public function test_消して入れ直す保存でも写しは最後に1回だけ合わせる(): void
    {
        $d = PersonFactory::new()->create();
        $sd = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => '2027-03-01']);
        $this->row($p->id, $d->id, 'D');
        $this->row($p->id, $sd->id, 'SD');
        $before = ProjectHistory::where('project_id', $p->id)->count();

        // 日別ボードと同じ形：まとめて消す → SD → D の順に入れ直す。
        DB::transaction(function () use ($p, $d, $sd) {
            Assignment::where('project_id', $p->id)->delete();
            $this->row($p->id, $sd->id, 'SD');
            $this->row($p->id, $d->id, 'D');
            DirectorSync::queueRefresh($p->id);
        });

        $this->assertSame($d->id, $p->fresh()->director_id);
        $this->assertSame($before, ProjectHistory::where('project_id', $p->id)->count(), '途中の状態が編集履歴に残っている');
    }

    public function test_まとめて消してDがいなくなったら写しも消える(): void
    {
        $d = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => '2027-03-01']);
        $this->row($p->id, $d->id, 'D');

        DB::transaction(function () use ($p) {
            Assignment::where('project_id', $p->id)->delete();
            DirectorSync::queueRefresh($p->id);
        });

        $this->assertNull($p->fresh()->director_id);
    }

    public function test_開催日が無い案件の写しは触らない(): void
    {
        $d = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => null, 'director_id' => $d->id]);

        DirectorSync::refreshMirror($p->id);

        $this->assertSame($d->id, $p->fresh()->director_id);
    }
}
