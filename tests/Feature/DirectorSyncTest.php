<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D（ディレクター）・SD の保存の一本化（2026-08-05 baba確定・移行①）。
 *
 * これまで入口ごとに保存先が違っていた：
 *   ・D決め画面（/assign-director）→ assignments
 *   ・案件一覧のプルダウン        → projects.director_id / sd_id
 * ＝D決め画面で決めても他の画面に出てこなかった。
 *
 * 移行①のふるまい：
 *   ・どちらの入口でも **assignments に保存される**（一本化）
 *   ・同時に古い列（director_id / sd_id）にも同じ値が「写し」で入る（表示が古い列を読む画面のため）
 *   ・CSV取込のディレクター列は扱わない
 */
class DirectorSyncTest extends TestCase
{
    use RefreshDatabase;

    /** 案件一覧のプルダウンでDを決めると、assignments に入り、古い列にも写しが入る。 */
    public function test_cells_save_writes_assignments_and_mirrors_column(): void
    {
        $employee = PersonFactory::new()->create();
        $director = PersonFactory::new()->create(['name' => 'D 社員']);
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-01']);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id,
            'director_id' => $director->id,
        ])->assertOk();

        // ① assignments に role='D' で入る（＝一本化された保存先）
        $row = Assignment::where('project_id', $project->id)->where('role', 'D')->first();
        $this->assertNotNull($row, '案件一覧から決めたDが assignments に入っていない');
        $this->assertSame($director->id, $row->staff_id);
        $this->assertSame('2026-09-01', $row->date->format('Y-m-d'));
        $this->assertSame('仮', $row->status);

        // ② 古い列にも同じ値が写る（表示がまだ古い列を読む画面のため）
        $this->assertSame($director->id, $project->fresh()->director_id);
    }

    /** D決め画面で決めたDが、古い列にも写る＝案件一覧などにも出るようになる。 */
    public function test_assign_director_save_mirrors_to_old_column(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        $director = PersonFactory::new()->create();
        $sd = PersonFactory::new()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-02']);

        $this->actingAsPerson($manager)->post('/assign-director/save', [
            'dir' => [$project->id => $director->id],
            'sd' => [$project->id => $sd->id],
            'status' => '仮',
        ])->assertRedirect('/assign-director');

        $fresh = $project->fresh();
        $this->assertSame($director->id, $fresh->director_id, 'D決め画面のDが古い列に写っていない');
        $this->assertSame($sd->id, $fresh->sd_id);
        // assignments 側も従来どおり入っている
        $this->assertSame(2, Assignment::where('project_id', $project->id)->whereIn('role', ['D', 'SD'])->count());
    }

    /** 担当を「なし」に戻すと、assignments の行が消えて古い列も空になる。 */
    public function test_clearing_director_removes_assignment_and_column(): void
    {
        $employee = PersonFactory::new()->create();
        $director = PersonFactory::new()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-03']);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => $director->id,
        ])->assertOk();

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => '',
        ])->assertOk();

        $this->assertSame(0, Assignment::where('project_id', $project->id)->where('role', 'D')->count());
        $this->assertNull($project->fresh()->director_id);
    }

    /** Dだけ送ったときにSDは消えない（セル1つずつ変える運用のため）。 */
    public function test_saving_only_director_keeps_sd(): void
    {
        $employee = PersonFactory::new()->create();
        $d1 = PersonFactory::new()->create();
        $d2 = PersonFactory::new()->create();
        $sd = PersonFactory::new()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-04']);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => $d1->id, 'sd_id' => $sd->id,
        ])->assertOk();

        // Dだけ差し替える
        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => $d2->id,
        ])->assertOk();

        $fresh = $project->fresh();
        $this->assertSame($d2->id, $fresh->director_id);
        $this->assertSame($sd->id, $fresh->sd_id, 'Dだけ変えたのにSDが消えている');
        $this->assertSame($d2->id, Assignment::where('project_id', $project->id)->where('role', 'D')->value('staff_id'));
        $this->assertSame($sd->id, Assignment::where('project_id', $project->id)->where('role', 'SD')->value('staff_id'));
    }

    /** すでに「確定」の担当は、別の画面の操作で勝手に「仮」へ落ちない。 */
    public function test_existing_confirmed_status_is_kept(): void
    {
        $employee = PersonFactory::new()->create();
        $director = PersonFactory::new()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-05']);

        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $director->id,
            'date' => '2026-09-05', 'role' => 'D', 'status' => '確定',
        ]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => $director->id,
        ])->assertOk();

        $this->assertSame('確定', Assignment::where('project_id', $project->id)->where('role', 'D')->value('status'));
    }

    /** スタッフのIDや存在しないIDは担当にできない（社員だけ）。 */
    public function test_only_employees_can_be_director(): void
    {
        $employee = PersonFactory::new()->create();
        $staff = PersonFactory::new()->staff()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-06']);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => $staff->id,
        ])->assertOk();

        $this->assertNull($project->fresh()->director_id);
        $this->assertSame(0, Assignment::where('project_id', $project->id)->where('role', 'D')->count());
    }

    /** 開催日が未設定の案件は、assignmentsには書けないが写しだけ入る（日付を入れて再保存すれば入る）。 */
    public function test_project_without_date_only_mirrors(): void
    {
        $employee = PersonFactory::new()->create();
        $director = PersonFactory::new()->create();
        $project = ProjectFactory::new()->create(['start_date' => null]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'director_id' => $director->id,
        ])->assertOk();

        $this->assertSame($director->id, $project->fresh()->director_id);
        $this->assertSame(0, Assignment::where('project_id', $project->id)->count());
    }

    /** CSV取込の「ディレクター」列は取り込まない（取込時点ではDが決まっていないため）。 */
    public function test_csv_import_ignores_director_column(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        $director = PersonFactory::new()->create(['name' => '田中 健一']);

        $csv = "開催日,案件名,運営人数,ディレクター\n2026-09-10,テスト取込案件,10,田中 健一\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('projects.csv', $csv);

        $this->actingAsPerson($manager)->post('/project-import', ['csv' => $file]);

        $created = Project::where('project_name', 'テスト取込案件')->first();
        $this->assertNotNull($created, 'CSV取込で案件が作られていない');
        $this->assertNull($created->director_id, 'CSVのディレクター列を取り込んでしまっている');
        $this->assertSame(0, Assignment::where('project_id', $created->id)->count());
    }

    /**
     * ⚠ SDは複数入れられる（2026-09-02 baba要望）。
     *   大型案件はコンテンツごとにSDが2名いたりする。Dは1名のまま。
     */
    public function test_multiple_sd_can_be_saved(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        $director = PersonFactory::new()->create(['id' => 'E-D1']);
        $sd1 = PersonFactory::new()->create(['id' => 'E-S1']);
        $sd2 = PersonFactory::new()->create(['id' => 'E-S2']);
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-02']);

        $this->actingAsPerson($manager)->post('/assign-director/save', [
            'dir' => [$project->id => $director->id],
            'sd' => [$project->id => [$sd1->id, $sd2->id]],
            'status' => '仮',
        ])->assertRedirect();

        $sds = Assignment::where('project_id', $project->id)->where('role', 'SD')
            ->pluck('staff_id')->sort()->values()->all();
        $this->assertSame(['E-S1', 'E-S2'], $sds, 'SDが2名入っていない');

        // ⚠ 古い列は1人ぶんしか持てないので、先頭のSDだけ写す（本当のSDは assignments 側）。
        $this->assertSame('E-S1', $project->fresh()->sd_id);

        // 画面にも2名で渡る。
        $this->actingAsPerson($manager)->get('/assign-director')
            ->assertOk()
            ->assertViewHas('cases', function ($cases) use ($project) {
                $c = collect($cases)->firstWhere('id', $project->id);

                return $c && count($c['sdIds']) === 2;
            });
    }

    /** SDを1人だけ外しても、もう1人は残る。 */
    public function test_removing_one_sd_keeps_the_other(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        PersonFactory::new()->create(['id' => 'E-S1']);
        PersonFactory::new()->create(['id' => 'E-S2']);
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-02']);

        $this->actingAsPerson($manager)->post('/assign-director/save', [
            'sd' => [$project->id => ['E-S1', 'E-S2']],
        ])->assertRedirect();

        $this->actingAsPerson($manager)->post('/assign-director/save', [
            'sd' => [$project->id => ['E-S2']],
        ])->assertRedirect();

        $sds = Assignment::where('project_id', $project->id)->where('role', 'SD')
            ->pluck('staff_id')->all();
        $this->assertSame(['E-S2'], $sds);
    }

    /** ⚠ 古い形（社員IDを1つだけ文字列で送る）でも受ける＝他の入口が壊れない。 */
    public function test_a_single_sd_string_still_works(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        PersonFactory::new()->create(['id' => 'E-S9']);
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-02']);

        $this->actingAsPerson($manager)->post('/assign-director/save', [
            'sd' => [$project->id => 'E-S9'],
        ])->assertRedirect();

        $this->assertSame('E-S9', $project->fresh()->sd_id);
    }
}
