<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 案件CSV一括取込（POST /project-import → ProjectController@import）の結合テスト。
 *
 * 仕様書 IT-CSV-01 / IT-CSV-02 / IT-CSV-03 に対応。
 * import() の実装に合わせ、見出し行に日本語の列名を持つCSVを作って投げる。
 * 必須3項目＝「案件名」「開催日(YYYY-MM-DD)」「運営人数(1以上の整数)」。
 * コンテンツ名は案件名から発番/紐づけされ、project.content_ids に入る。
 */
class ProjectCsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** テスト用CSVを組み立てる（1行目＝見出し、以降＝データ行）。 */
    private function makeCsv(array $header, array $rows): string
    {
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return implode("\n", $lines);
    }

    /** IT-CSV-01：有効な行を2件含むCSV → projects に2件作成される。 */
    public function test_valid_csv_imports_projects(): void
    {
        $user = PersonFactory::new()->create();

        $csv = $this->makeCsv(
            ['案件名', '開催日', '運営人数'],
            [
                ['謎解き脱出ゲーム', '2026-07-20', '5'],
                ['サバイバルゲーム', '2026-08-15', '8'],
            ]
        );
        $file = UploadedFile::fake()->createWithContent('cases.csv', $csv);

        $response = $this->actingAsPerson($user)
            ->post('/project-import', ['csv' => $file]);

        $response->assertRedirect('/projects');
        $this->assertSame(2, Project::count());

        $p1 = Project::where('project_name', '謎解き脱出ゲーム')->first();
        $this->assertNotNull($p1);
        $this->assertSame(5, $p1->required_count);
        $this->assertStringStartsWith('2026-07-20', $p1->start_date->format('Y-m-d'));

        $p2 = Project::where('project_name', 'サバイバルゲーム')->first();
        $this->assertNotNull($p2);
        $this->assertSame(8, $p2->required_count);
        $this->assertStringStartsWith('2026-08-15', $p2->start_date->format('Y-m-d'));
    }

    /** IT-CSV-02：必須項目が欠けた行はスキップされ、正常行だけ登録される。 */
    public function test_rows_missing_required_fields_are_skipped(): void
    {
        $user = PersonFactory::new()->create();

        // 1件目=正常 / 2件目=開催日なし / 3件目=運営人数なし
        $csv = $this->makeCsv(
            ['案件名', '開催日', '運営人数'],
            [
                ['正常案件', '2026-07-20', '5'],
                ['開催日欠け', '', '5'],
                ['人数欠け', '2026-08-15', ''],
            ]
        );
        $file = UploadedFile::fake()->createWithContent('cases.csv', $csv);

        $response = $this->actingAsPerson($user)
            ->post('/project-import', ['csv' => $file]);

        $response->assertRedirect('/projects');
        // 登録されるのは正常な1件だけ。
        $this->assertSame(1, Project::count());
        $this->assertNotNull(Project::where('project_name', '正常案件')->first());
        $this->assertNull(Project::where('project_name', '開催日欠け')->first());
        $this->assertNull(Project::where('project_name', '人数欠け')->first());
    }

    /** IT-CSV-03：コンテンツ名（案件名）から contents が発番/紐づけされ content_ids に入る。 */
    public function test_content_name_is_linked_to_content_id(): void
    {
        $user = PersonFactory::new()->create();

        $csv = $this->makeCsv(
            ['案件名', '開催日', '運営人数'],
            [
                ['謎解き脱出ゲーム', '2026-07-20', '5'],
            ]
        );
        $file = UploadedFile::fake()->createWithContent('cases.csv', $csv);

        $this->actingAsPerson($user)
            ->post('/project-import', ['csv' => $file])
            ->assertRedirect('/projects');

        // コンテンツが新規発番されている。
        $content = Content::where('content_name', '謎解き脱出ゲーム')->first();
        $this->assertNotNull($content);

        // その content.id が作成された project の content_ids に反映されている。
        $project = Project::where('project_name', '謎解き脱出ゲーム')->first();
        $this->assertNotNull($project);
        $this->assertContains($content->id, $project->content_ids);
    }
}
