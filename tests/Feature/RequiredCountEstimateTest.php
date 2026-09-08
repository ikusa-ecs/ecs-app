<?php

namespace Tests\Feature;

use App\Models\ContentRoleRequirement;
use App\Models\Project;
use App\Support\RequiredCountEstimate;
use App\Support\RoleRequirementCsv;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CSV取込で運営人数が空欄のとき、参加人数とコンテンツから「仮」の人数を入れる
 * （2026-09-08 baba要望「今0になってるから最少人数5にしてほしい」）。
 *
 * 【なぜ要るか】
 * 運営人数が空だと、アプリ中の「必要◯名／あと◯名」がぜんぶ **0** になる。
 * ＝足りているように見え、自動アサインの対象からも外れる＝**気づかないまま人が足りない**。
 */
class RequiredCountEstimateTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->manager()->create(['office' => '東京', 'must_onboard' => false]);
    }

    /** CSVを1本作ってPOSTする。 */
    private function importCsv(string $csv)
    {
        return $this->actingAsPerson($this->manager())->post('/project-import', [
            'csv' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
        ]);
    }

    private function requireRoles(string $contentId, string $scale, array $roles): void
    {
        foreach ($roles as $pos => $n) {
            ContentRoleRequirement::create([
                'content_id' => $contentId, 'scale' => $scale, 'position' => $pos, 'count' => $n,
            ]);
        }
    }

    /**
     * ⚠ 参加人数 → 規模 の区切りは、実物の「必要アサイン人数リスト」のとおり。
     * （～49名=小型／50～99名=中型／100名以上=大型）。リストが変わったらここも直す。
     */
    public function test_scale_is_decided_by_guest_count(): void
    {
        $this->assertSame('小型', RequiredCountEstimate::scaleFromGuests(1));
        $this->assertSame('小型', RequiredCountEstimate::scaleFromGuests(49));
        $this->assertSame('中型', RequiredCountEstimate::scaleFromGuests(50));
        $this->assertSame('中型', RequiredCountEstimate::scaleFromGuests(99));
        $this->assertSame('大型', RequiredCountEstimate::scaleFromGuests(100));
        $this->assertSame('大型', RequiredCountEstimate::scaleFromGuests(500));
        // 分からないときは決めつけない。
        $this->assertNull(RequiredCountEstimate::scaleFromGuests(null));
        $this->assertNull(RequiredCountEstimate::scaleFromGuests(0));
        // 規模の名前は必要アサイン人数リストの正本と同じ3つ。
        $this->assertSame(['小型', '中型', '大型'], RoleRequirementCsv::SCALES);
    }

    /** 材料が何も無ければ最少の5名（0にしない）。 */
    public function test_falls_back_to_the_minimum(): void
    {
        $est = RequiredCountEstimate::for([], null, null);

        $this->assertSame(5, $est['count']);
        $this->assertSame(5, RequiredCountEstimate::MINIMUM);
        $this->assertFalse($est['fromTemplate']);
    }

    /** コンテンツの必要アサイン人数が登録されていれば、その合計を使う。 */
    public function test_uses_the_sum_of_the_position_template(): void
    {
        // 中型＝D1・MC1・OP1・FC4＝合計7名。
        $this->requireRoles('CT-1', '中型', ['D' => 1, 'MC' => 1, 'OP' => 1, 'FC' => 4]);

        $est = RequiredCountEstimate::for(['CT-1'], null, 60);   // 参加60名→中型

        $this->assertSame(7, $est['count']);
        $this->assertSame('中型', $est['scale']);
        $this->assertTrue($est['fromTemplate']);
        $this->assertStringContainsString('参加人数60名', $est['reason']);
    }

    /** ⚠ 必要人数の合計が5名より少なくても、5名まで引き上げる（baba指定）。 */
    public function test_never_goes_below_five(): void
    {
        $this->requireRoles('CT-2', '小型', ['D' => 1, 'MC' => 1]);   // 合計2名

        $est = RequiredCountEstimate::for(['CT-2'], null, 20);

        $this->assertSame(5, $est['count']);
        $this->assertStringContainsString('最少の5名', $est['reason']);
    }

    /** 案件規模が書いてあれば、参加人数より規模を優先する（人が決めたものを尊重）。 */
    public function test_written_scale_wins_over_guest_count(): void
    {
        $this->requireRoles('CT-3', '大型', ['D' => 1, 'FC' => 20]);   // 大型＝21名
        $this->requireRoles('CT-3', '小型', ['D' => 1, 'FC' => 4]);    // 小型＝5名

        // 参加人数は10名（＝ふつうなら小型）だが、規模に「大型」と書いてある。
        $est = RequiredCountEstimate::for(['CT-3'], '大型', 10);

        $this->assertSame(21, $est['count']);
        $this->assertSame('大型', $est['scale']);
    }

    /**
     * CSV取込：運営人数が空欄でも取り込めて、仮の人数が入る（前はエラーで落ちていた）。
     */
    public function test_csv_import_fills_a_provisional_count(): void
    {
        $csv = "案件名,開催日,運営人数,お客様人数,案件規模\n"
            ."人数のない案件,2026-10-01,,60,\n";

        $this->importCsv($csv)->assertRedirect('/projects');

        $p = Project::where('project_name', '人数のない案件')->first();
        $this->assertNotNull($p, '運営人数が空だと取り込めていない');
        // コンテンツの必要人数は未登録なので最少の5名。
        $this->assertSame(5, (int) $p->required_count);
        $this->assertTrue((bool) $p->count_tentative, '仮の印（count_tentative）が立っていない');
        $this->assertSame(60, (int) $p->guest_count);
    }

    /** コンテンツの必要人数が登録されていれば、その合計で入る。 */
    public function test_csv_import_uses_the_template_when_registered(): void
    {
        // CSVの案件名がコンテンツ名になる＝先にそのコンテンツの必要人数を入れておく。
        $content = \App\Models\Content::create(['id' => 'CT-9', 'content_name' => '謎解き', 'active' => true]);
        $this->requireRoles($content->id, '大型', ['D' => 1, 'MC' => 1, 'OP' => 1, 'FC' => 9]);   // 12名

        $csv = "案件名,開催日,運営人数,お客様人数\n"
            ."謎解き,2026-10-02,,120\n";   // 参加120名→大型

        $this->importCsv($csv)->assertRedirect('/projects');

        $p = Project::where('project_name', '謎解き')->first();
        $this->assertNotNull($p);
        $this->assertSame(12, (int) $p->required_count);
        $this->assertTrue((bool) $p->count_tentative);
    }

    /** 運営人数が書いてあるときは、今までどおりそのまま入る（仮の印は付かない）。 */
    public function test_written_count_is_kept_as_is(): void
    {
        $csv = "案件名,開催日,運営人数\n"
            ."人数がある案件,2026-10-03,16\n";

        $this->importCsv($csv)->assertRedirect('/projects');

        $p = Project::where('project_name', '人数がある案件')->first();
        $this->assertSame(16, (int) $p->required_count);
        $this->assertFalse((bool) $p->count_tentative, '書いてある人数に仮の印を付けている');
    }

    /** ⚠ 書いてあるのに読めない人数は、今までどおりエラーにする（勘で埋めない）。 */
    public function test_unreadable_count_is_still_an_error(): void
    {
        $csv = "案件名,開催日,運営人数\n"
            ."読めない案件,2026-10-04,たくさん\n";

        $this->importCsv($csv)->assertRedirect('/projects');

        $this->assertNull(Project::where('project_name', '読めない案件')->first());
    }

    /** 仮で入れたことを、取り込みのメッセージで必ず知らせる（黙って入れない）。 */
    public function test_the_message_says_what_was_estimated(): void
    {
        $csv = "案件名,開催日,運営人数,お客様人数\n"
            ."お知らせ確認,2026-10-05,,30\n";

        $this->importCsv($csv)
            ->assertSessionHas('status', function (string $msg) {
                return str_contains($msg, '運営人数が空欄だった1件は「仮」で入れました')
                    && str_contains($msg, '最少の5名');
            });
    }
}
