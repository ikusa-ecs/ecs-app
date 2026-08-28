<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Support\AssignmentRole;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D決め（/assign-director）で「社員一覧に居ない人」の名前が出ること。2026-08-26 baba指摘。
 *
 * ⚠ 直す前は `S-015` のような番号がそのまま画面に出ていた。
 *   原因＝この画面が名前を引くのは社員一覧だけで、見つからないとIDを出す作りだったこと。
 *   D・SD・フロア（FC）に**スタッフ**が入っていることが実際にある
 *   （アサイン表の取込で、その欄の名前がスタッフ名簿の人と一致した場合など）。
 */
class DirectorBoardNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_assigned_as_director_is_named_not_shown_as_id(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $staff = PersonFactory::new()->create([
            'id' => 'S-015', 'name' => '渡辺 さくら', 'role' => 'staff', 'office' => '東京',
        ]);
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'status' => '確定',
            'start_date' => now()->startOfMonth()->addDays(5)->format('Y-m-d'),
        ]);
        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $staff->id,
            'date' => $project->start_date, 'role' => AssignmentRole::D, 'status' => '確定',
        ]);

        $others = $this->actingAsPerson($me)->get('/assign-director')
            ->assertOk()->viewData('others');

        $this->assertArrayHasKey('S-015', $others->all(), 'IDでなく名前を引けるように渡していること');
        // ⚠ スタッフの氏名は空白を詰めて保存する運用（2026-08-28）。
        //   苗字だけを出す画面では、スタッフは氏名まるごとが出る（区切りが無いため）。
        $this->assertSame('渡辺さくら', $others['S-015']['surname']);
        $this->assertSame('スタッフ', $others['S-015']['kind'], '社員でないことが分かるように出す');
    }

    /** 社員一覧に居る人は others に入れない（二重に持たない）。 */
    public function test_employee_is_not_listed_as_other(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $d = PersonFactory::new()->create([
            'id' => 'E-010', 'name' => '田中 健一', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'status' => '確定',
            'start_date' => now()->startOfMonth()->addDays(5)->format('Y-m-d'),
        ]);
        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $d->id,
            'date' => $project->start_date, 'role' => AssignmentRole::D, 'status' => '確定',
        ]);

        $others = $this->actingAsPerson($me)->get('/assign-director')
            ->assertOk()->viewData('others');

        $this->assertArrayNotHasKey('E-010', $others->all());
    }
}
