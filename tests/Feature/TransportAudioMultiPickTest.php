<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 移動・車両／音響機材を「いくつでも選べる」ようにした（2026-08-25 baba要望）。
 *
 * 【持ち方】列はこれまでどおり1つの文字のまま。選んだものを「+」でつないで入れる
 *   （例：電車+IKUSAカー）。マスタに元からある「電車+IKUSAカー」と同じ書き方なので、
 *   一覧・アサイン表・書き出しは何も変えずに動く＝列の作り替えが要らない。
 *
 * ⚠ 画面はマスタの「電車+IKUSAカー」のような組み合わせを**ばらして**1つずつの
 *   チェックにする（同じものが二重に並ばないように）。
 */
class TransportAudioMultiPickTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 案件登録フォームから、つないだ値をそのまま保存できる。 */
    public function test_project_form_saves_joined_values(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'   => '謎解き',
            'start_date'      => '2026-09-01',
            'required_count'  => '8',
            'transport'       => '電車+IKUSAカー+レンタカー',
            'audio_equipment' => 'CUBE+TOA',
            'intent'          => 'publish',
        ])->assertRedirect('/projects');

        $p = Project::firstOrFail();
        $this->assertSame('電車+IKUSAカー+レンタカー', $p->transport);
        $this->assertSame('CUBE+TOA', $p->audio_equipment);
    }

    /** 案件一覧のその場保存でも、つないだ値を受ける。 */
    public function test_project_list_cell_save_accepts_joined_values(): void
    {
        $project = ProjectFactory::new()->create(['office' => '東京', 'transport' => '電車']);

        $this->actingAsPerson($this->manager())->postJson('/projects/cells', [
            'id'        => $project->id,
            'transport' => '電車+IKUSAカー',
        ])->assertOk();

        $this->assertSame('電車+IKUSAカー', $project->fresh()->transport);
    }

    /** 全部のチェックを外したら未設定（null）に戻る。 */
    public function test_clearing_all_picks_becomes_null(): void
    {
        $project = ProjectFactory::new()->create(['office' => '東京', 'transport' => '電車+IKUSAカー']);

        $this->actingAsPerson($this->manager())->postJson('/projects/cells', [
            'id'        => $project->id,
            'transport' => '',
        ])->assertOk();

        $this->assertNull($project->fresh()->transport);
    }

    /** アサイン表のその場編集でも、つないだ値を受ける。 */
    public function test_assign_sheet_accepts_joined_values(): void
    {
        $project = ProjectFactory::new()->create(['office' => '東京']);

        $this->actingAsPerson($this->manager())->post('/assign-sheet/project', [
            'project_id' => $project->id,
            'field'      => 'audio_equipment',
            'value'      => 'CUBE+SANWA',
        ])->assertOk();

        $this->assertSame('CUBE+SANWA', $project->fresh()->audio_equipment);
    }

    /** たくさん選んでも保存できる（つないだ文字が長くなっても弾かれない）。 */
    public function test_many_picks_are_not_rejected_by_length(): void
    {
        $long = 'IKUSAカー+IKUSAカー2台+IKUSAカー3台+電車+レンタカー+飛行機';

        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-09-01',
            'required_count' => '8',
            'transport'      => $long,
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $this->assertSame($long, Project::firstOrFail()->transport);
    }

    /** 登録フォームが、プルダウンではなく複数選べる欄になっている。 */
    public function test_form_has_multi_pick_boxes(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/project-form')->assertOk()->getContent();

        $this->assertStringContainsString('id="transportPick"', $html);
        $this->assertStringContainsString('id="audioPick"', $html);
        // 送信用の入れ物は残っている（保存の形は今までと同じ1つの文字）。
        $this->assertStringContainsString('name="transport"', $html);
        $this->assertStringContainsString('name="audio_equipment"', $html);
    }

    /** 案件一覧の詳細も、複数選べる形になっている。 */
    public function test_project_list_has_multi_pick(): void
    {
        ProjectFactory::new()->create(['office' => '東京']);

        $html = $this->actingAsPerson($this->manager())->get('/projects')->assertOk()->getContent();

        $this->assertStringContainsString('multiPickHtml', $html);
        $this->assertStringContainsString('onMultiPickSave', $html);
    }
}
