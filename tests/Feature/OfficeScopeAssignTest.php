<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 拠点で見える範囲を絞る（全拠点運用・設計書19.2／2026-08-05 baba確定の第1弾）。
 *
 * 対象＝D決め（/assign-director）とアサインする場所（/project-assign）。
 *  ・一般社員 … 自拠点の案件・自拠点の人だけ（拠点スイッチは出ない）
 *  ・管理者以上 … 既定は全拠点。?office=◯◯ で1拠点に絞れる
 *  ・自拠点に共有（ヘルプ/巻き取り）された他拠点の案件も見える
 *  ・⚠ すでに担当に入っている他拠点の人は、絞っても候補に残す
 *     （この2画面の保存は「画面に出ている人で上書き」なので、消えると保存時に担当が外れてしまう）
 */
class OfficeScopeAssignTest extends TestCase
{
    use RefreshDatabase;

    // ※ 拠点マスタ（offices）は migration が6拠点を初期投入するので、テストで作る必要はない。

    /** 一般社員のD決め画面には、自拠点の案件と自拠点の社員だけが並ぶ。 */
    public function test_assign_director_shows_only_own_office_for_employee(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        PersonFactory::new()->create(['office' => '大阪']);

        $tokyoProject = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-01']);
        ProjectFactory::new()->create(['office' => '大阪', 'start_date' => '2026-09-02']);

        $data = $this->actingAsPerson($tokyoEmp)->get('/assign-director')
            ->assertOk()
            ->original->getData();

        $this->assertSame('東京', $data['officeScope'], '一般社員は自拠点で固定される');
        $this->assertSame([$tokyoProject->id], collect($data['cases'])->pluck('id')->all());
        $this->assertSame([$tokyoEmp->id], collect($data['employees'])->pluck('id')->all());
    }

    /** 自拠点に共有（ヘルプ/巻き取り）された他拠点の案件も、D決め画面に出る。 */
    public function test_assign_director_includes_projects_shared_to_my_office(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $osakaProject = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => '2026-09-03']);
        ProjectShare::create(['project_id' => $osakaProject->id, 'office' => '東京', 'kind' => 'ヘルプ']);

        $data = $this->actingAsPerson($tokyoEmp)->get('/assign-director')
            ->assertOk()
            ->original->getData();

        $this->assertSame([$osakaProject->id], collect($data['cases'])->pluck('id')->all());
    }

    /** すでにD/FCに入っている他拠点の社員は、拠点で絞っても候補に残る（保存で担当が外れないように）。 */
    public function test_assign_director_keeps_already_assigned_people_from_other_offices(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $osakaHelper = PersonFactory::new()->create(['office' => '大阪']);
        $osakaOther = PersonFactory::new()->create(['office' => '大阪']);

        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-04']);
        Assignment::create([
            'project_id' => $project->id,
            'staff_id' => $osakaHelper->id,
            'date' => '2026-09-04',
            'role' => 'D',
            'status' => '仮',
        ]);

        $ids = collect(
            $this->actingAsPerson($tokyoEmp)->get('/assign-director')
                ->assertOk()
                ->original->getData()['employees']
        )->pluck('id')->all();

        $this->assertContains($osakaHelper->id, $ids, 'すでにDに入っている他拠点の人は残す');
        $this->assertNotContains($osakaOther->id, $ids, '関係のない他拠点の人は出さない');
    }

    /** 管理者は既定で全拠点。?office= を付けたときだけ1拠点に絞られる。 */
    public function test_manager_sees_all_offices_and_can_switch(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '東京']);
        $tokyoProject = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-05']);
        $osakaProject = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => '2026-09-06']);

        $all = $this->actingAsPerson($manager)->get('/assign-director')
            ->assertOk()
            ->original->getData();
        $this->assertNull($all['officeScope'], '拠点未選択＝全拠点');
        $this->assertEqualsCanonicalizing(
            [$tokyoProject->id, $osakaProject->id],
            collect($all['cases'])->pluck('id')->all()
        );

        $osaka = $this->actingAsPerson($manager)->get('/assign-director?office=' . urlencode('大阪'))
            ->assertOk()
            ->original->getData();
        $this->assertSame('大阪', $osaka['officeScope']);
        $this->assertSame([$osakaProject->id], collect($osaka['cases'])->pluck('id')->all());
    }

    /** アサイン画面の候補スタッフは自拠点だけ。ただしこの案件に入っている他拠点の人は残す。 */
    public function test_project_assign_limits_staff_candidates_to_my_office(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $tokyoStaff = PersonFactory::new()->staff()->create(['office' => '東京']);
        $osakaStaff = PersonFactory::new()->staff()->create(['office' => '大阪']);
        $osakaHelper = PersonFactory::new()->staff()->create(['office' => '大阪']);

        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-07']);
        Assignment::create([
            'project_id' => $project->id,
            'staff_id' => $osakaHelper->id,
            'date' => '2026-09-07',
            'role' => 'OP',
            'status' => '確定',
        ]);

        $ids = collect(
            $this->actingAsPerson($tokyoEmp)->get('/project-assign?project=' . urlencode($project->id))
                ->assertOk()
                ->original->getData()['staff']
        )->pluck('id')->all();

        $this->assertContains($tokyoStaff->id, $ids);
        $this->assertContains($osakaHelper->id, $ids, 'すでにこの案件に入っている他拠点のスタッフは残す');
        $this->assertNotContains($osakaStaff->id, $ids, '関係のない他拠点のスタッフは出さない');
    }

    /** 拠点が空の人は「東京」扱い（実データ投入で拠点を埋め直すまでの保険）。 */
    public function test_people_without_office_are_treated_as_tokyo(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $noOffice = PersonFactory::new()->staff()->create(['office' => null]);
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-08']);

        $ids = collect(
            $this->actingAsPerson($tokyoEmp)->get('/project-assign?project=' . urlencode($project->id))
                ->assertOk()
                ->original->getData()['staff']
        )->pluck('id')->all();

        $this->assertContains($noOffice->id, $ids);
    }
}
