<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ProjectShare;
use App\Support\ProjectContentName;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件の見出しに出す「コンテンツ名」（2026-09-18 baba指摘
 * 「案件一覧で複数コンテンツ選択してたら最初のコンテンツしか表示されていない」）。
 *
 * ⚠ もとの原因は、各画面が `content_ids[0]`（＝1個目だけ）を見出しにしていたこと。
 *   同じ1行が10か所にコピーされていたので、1か所直しても他の画面では1個目のままだった。
 *   いまは正本＝App\Support\ProjectContentName の1か所だけが決める。
 *
 * 見張るのは4つ。
 *   ① 複数のコンテンツが「A・B」と全部出る（案件一覧）
 *   ② 台帳に登録しない「単発コンテンツ」も出る
 *   ③ 古い案件（content_names が空）は今までどおり台帳の名前から復元する
 *   ④ ⚠ どの画面も1個目だけを見ていない（`content_ids[0]` の書き方が復活していない）
 */
class ProjectContentNameTest extends TestCase
{
    use RefreshDatabase;

    private function employee()
    {
        return PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);
    }

    private function projectCase(string $projectId): ?array
    {
        return collect(
            $this->actingAsPerson($this->employee())->get('/projects')->assertOk()->original->getData()['cases']
        )->firstWhere('id', $projectId);
    }

    /** ① 複数コンテンツは「A・B」と全部出る。 */
    public function test_project_list_shows_every_content(): void
    {
        Content::create(['id' => 'C-001', 'content_name' => '謎パ']);
        Content::create(['id' => 'C-002', 'content_name' => '戦国運動会']);

        $p = ProjectFactory::new()->create([
            'office' => '東京',
            'content_ids' => ['C-001', 'C-002'],
            'content_names' => ['謎パ', '戦国運動会'],
            'project_name' => '謎パ・戦国運動会',
        ]);

        $this->assertSame('謎パ・戦国運動会', $this->projectCase($p->id)['content']);
    }

    /** ② 台帳に登録しない「単発コンテンツ」も見出しに出る。 */
    public function test_one_off_content_is_shown(): void
    {
        Content::create(['id' => 'C-001', 'content_name' => '謎パ']);

        $p = ProjectFactory::new()->create([
            'office' => '東京',
            'content_ids' => ['C-001'],
            'content_names' => ['謎パ', 'この案件だけの企画'],
            'project_name' => '謎パ・この案件だけの企画',
        ]);

        $this->assertSame('謎パ・この案件だけの企画', $this->projectCase($p->id)['content']);
    }

    /** ③ 古い案件（名前を持っていない）は台帳の名前から復元する。 */
    public function test_old_projects_fall_back_to_the_master_names(): void
    {
        Content::create(['id' => 'C-001', 'content_name' => '謎パ']);
        Content::create(['id' => 'C-002', 'content_name' => '戦国運動会']);

        $p = ProjectFactory::new()->create([
            'office' => '東京',
            'content_ids' => ['C-001', 'C-002'],
            'content_names' => null,
            'project_name' => '古い案件',
        ]);

        // 対応表を渡さなくても（1件だけ作るとき）台帳を引いて復元できる。
        $this->assertSame('謎パ・戦国運動会', ProjectContentName::of($p->fresh()));
        $this->assertSame('謎パ・戦国運動会', $this->projectCase($p->id)['content']);
    }

    /** コンテンツが何も分からない案件は、今までどおり案件名で代用する。 */
    public function test_unknown_content_falls_back_to_the_project_name(): void
    {
        $p = ProjectFactory::new()->create([
            'office' => '東京',
            'content_ids' => null,
            'content_names' => null,
            'project_name' => '（コンテンツ未定）',
        ]);

        $this->assertSame('（コンテンツ未定）', $this->projectCase($p->id)['content']);
        // 空を渡したときは空のまま（人数確定リマインドが「空の行」を飛ばすのに使う）。
        $this->assertSame('', ProjectContentName::of($p->fresh(), [], ''));
    }

    /**
     * ④ ⚠ 見張り。どの画面も「1個目だけ」を見ていないこと。
     * この書き方が戻ると、また画面によって名前が違う状態になる。
     */
    public function test_no_screen_reads_only_the_first_content(): void
    {
        $hits = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            // 正本そのもの（説明文に昔の書き方を引用している）は対象外。
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'ProjectContentName.php') {
                $body = file_get_contents($file->getPathname());
                if (str_contains($body, 'content_ids[0]')) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, "「1個目のコンテンツだけ」を見ている場所が残っています。\n".
            'App\Support\ProjectContentName を使ってください：'.implode(', ', $hits));
    }

    /**
     * 巻き取りの札（2026-09-18 baba要望）。
     * 自拠点（東京）の案件一覧でも「名古屋巻き取り」と分かること。
     * ⚠ 巻き取り＝相手の拠点が運営する。ふつうの案件と同じ見た目で並ぶと取り違える。
     */
    public function test_taken_over_projects_show_the_office_tag_in_my_own_list(): void
    {
        $p = ProjectFactory::new()->create([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);

        ProjectShare::create(['project_id' => $p->id, 'office' => '名古屋', 'kind' => '巻き取り']);

        $case = $this->projectCase($p->id);

        $this->assertSame(
            [['label' => '名古屋巻き取り', 'kind' => '巻き取り']],
            $case['shareTags'],
            '自拠点の一覧に「名古屋巻き取り」の札が出ていません'
        );
    }

    /** 自分の拠点ぶんは札にしない（「自拠点にコピー済」で別に出しているため二重になる）。 */
    public function test_my_own_office_is_not_repeated_as_a_tag(): void
    {
        $p = ProjectFactory::new()->create([
            'office' => '名古屋',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);

        ProjectShare::create(['project_id' => $p->id, 'office' => '東京', 'kind' => '巻き取り']);

        $case = $this->projectCase($p->id);

        $this->assertNotNull($case, '東京が巻き取った案件が一覧に出ていません');
        $this->assertSame([], $case['shareTags']);
        $this->assertTrue($case['sharedToMe']);
    }
}
