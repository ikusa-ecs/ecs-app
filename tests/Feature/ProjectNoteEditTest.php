<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectHistory;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件の備考（projects.note）を、アサイン系の画面からその場で直せる（2026-08-21 baba要望）。
 *
 * これまで＝備考は「見えるだけ」。直すには案件登録の画面か公開ボードまで戻る必要があった。
 * これから＝日別ボード／案件別アサイン／ピックアップ／アサイン表の帯から直せる。
 * 保存の入口は POST /projects/cells の1か所に寄せている
 * ＝拠点チェック（他拠点は書けない）と編集履歴（誰がいつ何に変えたか）が自動で効く。
 * 見た目とJSは resources/views/partials/project_note.blade.php の1か所。
 */
class ProjectNoteEditTest extends TestCase
{
    use RefreshDatabase;

    private function soon(int $plus = 4): string
    {
        return Carbon::today()->addDays($plus)->format('Y-m-d');
    }

    /** 備考を保存できる。他の項目は触らない。 */
    public function test_note_can_be_saved(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->soon(),
            'note' => '雨天時は体育館', 'required_count' => 12,
        ]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id,
            'note' => "前日設営あり\n15時集合",
        ])->assertOk();

        $project->refresh();
        $this->assertSame("前日設営あり\n15時集合", $project->note, '改行も残る');
        $this->assertSame(12, $project->required_count, '送っていない項目はそのまま');
    }

    /** 空にすると未記入（null）に戻る。 */
    public function test_empty_note_clears_it(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'note' => '消す予定のメモ',
        ]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'note' => '   ',
        ])->assertOk();

        $this->assertNull($project->fresh()->note);
    }

    /** 編集履歴に残る（誰がいつ何に変えたか）。 */
    public function test_note_change_is_recorded_in_history(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京', 'name' => '直した社員']);
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'note' => '前のメモ',
        ]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id' => $project->id, 'note' => 'あとのメモ',
        ])->assertOk();

        $row = ProjectHistory::where('project_id', $project->id)->where('field', 'note')->first();
        $this->assertNotNull($row, '備考の変更が履歴に残る');
        $this->assertSame('あとのメモ', $row->new_value);
    }

    /** 一般社員は他拠点の案件の備考を書き換えられない（保存の入口で弾く）。 */
    public function test_other_office_note_is_denied(): void
    {
        $tokyo = PersonFactory::new()->create(['office' => '東京', 'permission' => 'employee']);
        $nagoya = ProjectFactory::new()->create([
            'office' => '名古屋', 'start_date' => $this->soon(), 'note' => 'さわらせない',
        ]);

        $this->actingAsPerson($tokyo)->postJson('/projects/cells', [
            'id' => $nagoya->id, 'note' => '書き換えた',
        ])->assertStatus(403);

        $this->assertSame('さわらせない', Project::find($nagoya->id)->note);
    }
}
