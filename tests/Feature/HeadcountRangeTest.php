<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 運営人数を「6〜8人」のように おおよそ で入れられる（2026-08-25 baba要望）。
 *
 * 【持ち方】required_count＝範囲の**多いほう**（＝計算に使う数字・今までと同じ意味）／
 *          required_count_min＝**少ないほう**（範囲でないときは null）。
 *
 * ⚠ 多いほうを今までの列に入れているので、「残り○名」「必要○名」の計算は
 *   ひとつも作り替えていない。「8人埋まって初めて満員」（baba決定）がそのまま成り立つ。
 * ⚠ 数字だけ抜き出す書き方だと「6〜8」が「68」になる（CSV取込が実際にそうなっていた）。
 */
class HeadcountRangeTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 案件の登録フォームから「6〜8」で保存できる。 */
    public function test_project_form_accepts_a_range(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'  => '謎解き',
            'start_date'     => '2026-09-01',
            'required_count' => '6〜8',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $p = Project::firstOrFail();
        $this->assertSame(8, $p->required_count, '計算に使う数字は多いほう');
        $this->assertSame(6, $p->required_count_min, '少ないほうも残る');
    }

    /** 1つの数字だけなら今までどおり（下限は空のまま）。 */
    public function test_single_number_keeps_working(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-09-01',
            'required_count' => '10',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $p = Project::firstOrFail();
        $this->assertSame(10, $p->required_count);
        $this->assertNull($p->required_count_min);
    }

    /** 範囲を1つの数字に直すと、下限は消える（前の値が残らない）。 */
    public function test_editing_back_to_single_number_clears_the_lower_bound(): void
    {
        $project = ProjectFactory::new()->create(['required_count' => 8, 'required_count_min' => 6]);

        $this->actingAsPerson($this->manager())->post('/project-form', [
            'project_id'     => $project->id,
            'content_names'  => '謎解き',
            'start_date'     => '2026-09-01',
            'required_count' => '9',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $p = $project->fresh();
        $this->assertSame(9, $p->required_count);
        $this->assertNull($p->required_count_min);
    }

    /** アサイン表のカードからも範囲で直せる。 */
    public function test_assign_sheet_can_save_a_range(): void
    {
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'required_count' => 5, 'required_count_min' => null,
        ]);

        $this->actingAsPerson($this->manager())->post('/assign-sheet/project', [
            'project_id' => $project->id,
            'field'      => 'required_count',
            'value'      => '6〜8',
        ])->assertOk();

        $p = $project->fresh();
        $this->assertSame(8, $p->required_count);
        $this->assertSame(6, $p->required_count_min);
    }

    /** アサイン表で空にしたら未入力に戻る。 */
    public function test_assign_sheet_can_clear_the_count(): void
    {
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'required_count' => 8, 'required_count_min' => 6,
        ]);

        $this->actingAsPerson($this->manager())->post('/assign-sheet/project', [
            'project_id' => $project->id,
            'field'      => 'required_count',
            'value'      => '',
        ])->assertOk();

        $p = $project->fresh();
        $this->assertNull($p->required_count);
        $this->assertNull($p->required_count_min);
    }

    /** アサイン表の画面には「6〜8」の形で出る。 */
    public function test_assign_sheet_shows_the_range(): void
    {
        ProjectFactory::new()->create([
            'office' => '東京', 'project_name' => '謎解き',
            'start_date' => '2026-09-01', 'required_count' => 8, 'required_count_min' => 6,
        ]);

        $html = $this->actingAsPerson($this->manager())
            ->get('/assign-sheet?month=2026-09')->assertOk()->getContent();

        $this->assertStringContainsString('6〜8', $html);
    }

    /** 過去案件の取込でも範囲を読む（「6〜8」が「68」にならない）。 */
    public function test_past_import_reads_a_range(): void
    {
        $header = 'No.,日程,コンテンツ,顧客名(代理店名),運営人数';
        $body = '1,2026-01-20,水合戦,株式会社テスト,6〜8名';
        $csv = UploadedFile::fake()->createWithContent('past.csv', $header."\n".$body."\n");

        $this->actingAsPerson($this->manager())
            ->post('/past-import', ['csv' => $csv])
            ->assertRedirect('/past-import');

        $p = Project::firstOrFail();
        $this->assertSame(8, $p->required_count, '68 になっていないこと');
        $this->assertSame(6, $p->required_count_min);
    }

    /** 案件のCSV取込でも範囲を読む（数字1つだけの今までのCSVもそのまま通る）。 */
    public function test_project_csv_import_reads_a_range(): void
    {
        $header = '開催日,案件名,運営人数';
        $csv = UploadedFile::fake()->createWithContent(
            'projects.csv',
            $header."\n2026-09-01,謎解き,6〜8\n2026-09-02,水合戦,10\n"
        );

        $this->actingAsPerson($this->manager())
            ->post('/project-import', ['csv' => $csv]);

        $range = Project::where('project_name', '謎解き')->firstOrFail();
        $this->assertSame(8, $range->required_count);
        $this->assertSame(6, $range->required_count_min);

        $plain = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertSame(10, $plain->required_count);
        $this->assertNull($plain->required_count_min);
    }

    /** 編集フォームを開き直すと「6〜8」の形で戻る（入れたとおりに直せる）。 */
    public function test_edit_form_shows_the_range_again(): void
    {
        ProjectFactory::new()->create([
            'office' => '東京', 'project_name' => '謎解き',
            'required_count' => 8, 'required_count_min' => 6,
        ]);

        $html = $this->actingAsPerson($this->manager())->get('/projects')->assertOk()->getContent();

        // 画面へは @json で渡るので、日本語（〜）はエスケープされた形で入る。
        $this->assertStringContainsString(
            json_encode('6〜8', JSON_UNESCAPED_SLASHES), $html);
    }
}
