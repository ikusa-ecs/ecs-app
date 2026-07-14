<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件登録（POST /project-form）の結合テスト。テスト仕様書 IT-PROJ-01 / IT-PROJ-02 / IT-PROJ-03。
 *
 * ・RefreshDatabase：テストごとにメモリ上のDBをまっさらにする（本番/開発データは触らない）。
 * ・actingAs()：ログイン画面を通さず「この人でログイン中」の状態を作る＝認証の作り替えに依存しない。
 */
class ProjectStoreTest extends TestCase
{
    use RefreshDatabase;

    /** IT-PROJ-01：社員が案件を新規登録すると projects に1件保存される。 */
    public function test_employee_can_register_a_new_project(): void
    {
        $employee = PersonFactory::new()->create();

        $res = $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-09-01',
            'required_count' => '10',
            'intent'         => 'publish',   // 「募集中」で保存
        ]);

        $res->assertRedirect('/projects');

        $this->assertDatabaseHas('projects', [
            'project_name'   => '水合戦',
            'required_count' => 10,
            'status'         => '未着手',   // publish 指定＝未着手（募集中）で保存される
        ]);

        // 日付はDBの保存形式（date / datetime）に依存しないよう、先頭一致で確認する。
        $project = Project::where('project_name', '水合戦')->first();
        $this->assertStringStartsWith('2026-09-01', (string) $project->start_date);
    }

    /** IT-PROJ-02：project_id 付きで送ると新規作成でなく既存案件が上書き更新される。 */
    public function test_posting_with_id_updates_the_existing_project(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = \Database\Factories\ProjectFactory::new()->create([
            'project_name'   => '旧・水合戦',
            'required_count' => 5,
        ]);

        $this->actingAsPerson($employee)->post('/project-form', [
            'project_id'     => $project->id,
            'content_names'  => '運動会',
            'start_date'     => '2026-10-01',
            'required_count' => '12',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        // 行が増えず（＝更新）、内容が書き換わっている。
        $this->assertSame(1, Project::count());
        $this->assertDatabaseHas('projects', [
            'id'             => $project->id,
            'project_name'   => '運動会',
            'required_count' => 12,
        ]);
    }

    /** IT-PROJ-03（一部）：不正な日付は登録できずバリデーションエラーになる。 */
    public function test_invalid_date_is_rejected(): void
    {
        $employee = PersonFactory::new()->create();

        $res = $this->actingAsPerson($employee)->post('/project-form', [
            'content_names' => '水合戦',
            'start_date'    => 'not-a-date',
            'intent'        => 'publish',
        ]);

        $res->assertSessionHasErrors('start_date');
        $this->assertSame(0, Project::count());
    }

    /** 権限：スタッフは案件登録画面に入れず、自分のスタッフ画面へ戻される。 */
    public function test_staff_cannot_register_a_project(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->post('/project-form', [
            'content_names' => '水合戦',
            'start_date'    => '2026-09-01',
            'intent'        => 'publish',
        ])->assertRedirect('/staff-portal');

        $this->assertSame(0, Project::count());
    }
}
