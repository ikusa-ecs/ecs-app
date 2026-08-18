<?php

namespace Tests\Feature;

use App\Models\ProjectHistory;
use App\Support\ProjectHistoryRecorder;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 案件の編集履歴（先-1・2026-08-18）のテスト。
 *
 * 確かめたいこと：
 *   ・案件を書き換えたら「誰が・どの項目を・何から何に」が残る（画面を通さずモデル保存でも残る＝入口が1か所）。
 *   ・記録は人が読める形（0/1 やIDではなく「屋内 → 屋外」「氏名」）。
 *   ・見た目が変わらない書き換え（null → 空文字）は残さない＝履歴が意味の無い行で埋まらない。
 *   ・他拠点の案件の履歴は一般社員に見せない（全拠点運用・設計書19.2）。
 */
class ProjectHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** 変更すると項目ごとに1行ずつ、人が読める形で残る。 */
    public function test_updating_a_project_records_readable_history(): void
    {
        $employee = PersonFactory::new()->create(['name' => '山田 太郎', 'office' => '東京']);
        $project  = ProjectFactory::new()->create([
            'office'         => '東京',
            'start_time'     => '12:00',
            'required_count' => 14,
            'is_outdoor'     => false,
        ]);

        $this->actingAsPerson($employee);

        $project->start_time = '09:30';
        $project->required_count = 12;
        $project->is_outdoor = true;
        $project->save();

        // 登録時の1行（action=created）は別なので、変更ぶんだけ数える。
        $histories = ProjectHistory::where('project_id', $project->id)->where('action', 'updated')->get();
        $this->assertCount(3, $histories, '変えた3項目ぶん、1項目1行で残る');

        $time = $histories->firstWhere('field', 'start_time');
        $this->assertSame('集合時間（スタッフ）', $time->field_label);
        $this->assertSame('12:00', $time->old_value);
        $this->assertSame('09:30', $time->new_value);
        $this->assertSame('山田 太郎', $time->person_name, '誰が変えたかが残る');
        $this->assertSame($employee->id, $time->person_id);

        // 0/1 ではなく日本語で残る（読む人が意味を分かるようにするため）。
        $outdoor = $histories->firstWhere('field', 'is_outdoor');
        $this->assertSame('屋内', $outdoor->old_value);
        $this->assertSame('屋外', $outdoor->new_value);
    }

    /** ディレクターはIDではなく氏名で残る。 */
    public function test_person_fields_are_recorded_as_names(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $director = PersonFactory::new()->create(['name' => '鈴木 花子', 'office' => '東京']);
        $project  = ProjectFactory::new()->create(['office' => '東京', 'director_id' => null]);

        $this->actingAsPerson($employee);

        $project->director_id = $director->id;
        $project->save();

        $row = ProjectHistory::where('project_id', $project->id)->where('field', 'director_id')->first();
        $this->assertNotNull($row);
        $this->assertSame('（空）', $row->old_value);
        $this->assertSame('鈴木 花子', $row->new_value);
    }

    /** 見た目が変わらない書き換え（null → 空文字）は残さない。 */
    public function test_meaningless_change_is_not_recorded(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $project  = ProjectFactory::new()->create(['office' => '東京', 'note' => null]);

        $this->actingAsPerson($employee);

        $project->note = '';
        $project->save();

        $this->assertSame(0, ProjectHistory::where('project_id', $project->id)->where('field', 'note')->count());
    }

    /** 新規登録も1行残る。 */
    public function test_creating_a_project_is_recorded(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $this->actingAsPerson($employee);

        $project = ProjectFactory::new()->create(['office' => '東京']);

        $row = ProjectHistory::where('project_id', $project->id)->where('action', 'created')->first();
        $this->assertNotNull($row, '案件を登録したことが残る');
    }

    /** 一括取込やシーダーのように「人が1件ずつ直したのではない」ときは残さない。 */
    public function test_recording_can_be_switched_off(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $this->actingAsPerson($employee);

        $project = ProjectHistoryRecorder::withoutRecording(
            fn () => ProjectFactory::new()->create(['office' => '東京'])
        );

        $this->assertSame(0, ProjectHistory::where('project_id', $project->id)->count());
        $this->assertTrue(ProjectHistoryRecorder::enabled(), '処理が終わったら元に戻る');
    }

    /** 案件編集画面の下に、その案件の履歴が出る。 */
    public function test_edit_form_shows_the_history_of_that_project(): void
    {
        $employee = PersonFactory::new()->create(['name' => '田中 一郎', 'office' => '東京']);
        $project  = ProjectFactory::new()->create(['office' => '東京', 'start_time' => '12:00']);

        $this->actingAsPerson($employee);
        $project->update(['start_time' => '10:00']);

        $res = $this->actingAsPerson($employee)->get('/project-form?project=' . urlencode($project->id));

        $res->assertOk();
        $res->assertSee('編集履歴');
        $res->assertSee('集合時間（スタッフ）');
        $res->assertSee('田中 一郎');
    }

    /** 一般社員には他拠点の案件の履歴を見せない（全拠点運用・設計書19.2）。 */
    public function test_history_screen_hides_other_office_projects(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京']);
        $mine  = ProjectFactory::new()->create(['office' => '東京', 'project_name' => '東京の案件']);
        $other = ProjectFactory::new()->create(['office' => '大阪', 'project_name' => '大阪の案件']);

        // 記録は「誰かが変えた」状態にしておく（ログイン前に作ると person は空になるがそれでよい）。
        $this->actingAsPerson($tokyo);
        $mine->update(['note' => '東京のメモ']);
        $other->update(['note' => '大阪のメモ']);

        $res = $this->actingAsPerson($tokyo)->get('/project-history?period=all');

        $res->assertOk();
        $res->assertSee('東京の案件');
        $res->assertDontSee('大阪の案件');
    }

    /**
     * 保存先テーブルがまだ無いサーバー（`php artisan migrate` 未実行）でも、
     * ①画面は開ける（500にしない）②案件の保存も通る。
     * 履歴は補助の機能なので、本体の業務を止めてはいけない。
     */
    public function test_missing_table_does_not_break_anything(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $project  = ProjectFactory::new()->create(['office' => '東京', 'start_time' => '12:00']);

        Schema::drop('project_histories');

        // ① 画面は開けて、案内が出る
        $res = $this->actingAsPerson($employee)->get('/project-history');
        $res->assertOk();
        $res->assertSee('まだ変更の記録はありません');

        // ② 案件の保存も通る（履歴だけ残らない）
        $project->update(['start_time' => '10:00']);
        $this->assertSame('10:00', $project->fresh()->start_time);

        // ③ 案件編集画面も開ける
        $this->actingAsPerson($employee)->get('/project-form?project=' . urlencode($project->id))->assertOk();
    }

    /** 管理者は全拠点の履歴を見られる。 */
    public function test_manager_sees_all_offices(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '東京']);
        $other   = ProjectFactory::new()->create(['office' => '大阪', 'project_name' => '大阪の案件']);

        $this->actingAsPerson($manager);
        $other->update(['note' => '大阪のメモ']);

        $res = $this->actingAsPerson($manager)->get('/project-history?period=all');

        $res->assertOk();
        $res->assertSee('大阪の案件');
    }
}
