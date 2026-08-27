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

    /**
     * 「同じ内容で次の日程を追加」＝保存したあと、同じ内容の新規フォームへ戻る（2026-08-21 baba）。
     * これまでは案件一覧に飛ばされてしまい、続けて登録できなかった。
     */
    public function test_save_and_next_returns_to_the_form_with_the_same_content(): void
    {
        $employee = PersonFactory::new()->create();

        $res = $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-09-01',
            'required_count' => '10',
            'intent'         => 'next',
        ]);

        $project = Project::where('project_name', '水合戦')->first();

        $this->assertNotNull($project, '案件は保存される');
        $res->assertRedirect('/project-form?copy=' . urlencode($project->id));
    }

    /**
     * 案件を保存し直しても、スタッフ公開ボードで入れた「スタッフに伝えること」は消えない。
     * 入力する場所を公開ボード1か所にまとめたため、案件フォームはこれらの列を触らない（2026-08-21）。
     */
    public function test_saving_a_project_does_not_wipe_staff_info(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = \Database\Factories\ProjectFactory::new()->create([
            'project_name'     => '水合戦',
            'assembly_detail'  => '東口改札を出て正面のバス停前',
            'staff_belongings' => '黒スーツ、スニーカー',
            'staff_dresscode'  => '上下黒',
            'staff_notes'      => '会場内は飲食禁止',
        ]);

        $this->actingAsPerson($employee)->post('/project-form', [
            'project_id'     => $project->id,
            'content_names'  => '水合戦',
            'start_date'     => '2026-09-01',
            'required_count' => '10',
            'intent'         => 'publish',
        ]);

        $project->refresh();

        $this->assertSame('東口改札を出て正面のバス停前', $project->assembly_detail);
        $this->assertSame('黒スーツ、スニーカー', $project->staff_belongings);
        $this->assertSame('上下黒', $project->staff_dresscode);
        $this->assertSame('会場内は飲食禁止', $project->staff_notes);
    }

    /**
     * スタッフの集合・解散を案件登録から保存できる（2026-08-27 baba要望）。
     * それまで入れられるのは公開ボードだけだった。保存先の列は同じ（staff_meet_time / staff_leave_time）。
     */
    public function test_staff_meet_and_leave_times_are_saved_from_the_form(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'    => '運動会',
            'start_date'       => '2026-09-10',
            'required_count'   => '10',
            'start_time'       => '07:00',
            'end_time'         => '19:00',
            'staff_meet_time'  => '08:30',
            'staff_leave_time' => '17:30',
            'intent'           => 'publish',
        ])->assertRedirect('/projects');

        $project = Project::where('project_name', '運動会')->first();
        $this->assertSame('08:30', $project->staff_meet_time);
        $this->assertSame('17:30', $project->staff_leave_time);
    }

    /**
     * スタッフの時間を空で送ったら null で持つ（＝「社員と同じ」）。
     * 空文字で入れると「入力済み」と見分けが付かず、スタッフ画面が社員の時間に落ちなくなる。
     */
    public function test_blank_staff_times_are_stored_as_null(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'    => '謎解き',
            'start_date'       => '2026-09-11',
            'required_count'   => '8',
            'start_time'       => '09:00',
            'end_time'         => '18:00',
            'staff_meet_time'  => '',
            'staff_leave_time' => '',
            'intent'           => 'publish',
        ])->assertRedirect('/projects');

        $project = Project::where('project_name', '謎解き')->first();
        $this->assertNull($project->staff_meet_time);
        $this->assertNull($project->staff_leave_time);
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
