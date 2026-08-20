<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\EventCount;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「イベント数として数える／数えない」（先人の要件定義 先-2）のテスト。
 *
 * 社内の数え方＝**体験会・EXPO は数えない**。/stats が全案件を数えていて社内の言い方とズレていた。
 * 判定の正本は App\Support\EventCount の1か所。案件ごとに手動で上書きできる。
 */
class EventCountTest extends TestCase
{
    use RefreshDatabase;

    private function thisMonth(int $day = 15): string
    {
        return Carbon::today()->startOfMonth()->addDays($day - 1)->format('Y-m-d');
    }

    /** 自動判定：体験会と、名前にEXPOを含む案件は数えない。 */
    public function test_auto_rule_excludes_taiken_and_expo(): void
    {
        $normal = ProjectFactory::new()->create(['format' => 'リアル', 'project_name' => '水合戦']);
        $taiken = ProjectFactory::new()->create(['format' => '体験会', 'project_name' => '水合戦']);
        $expo   = ProjectFactory::new()->create(['format' => 'リアル', 'project_name' => '謎解きEXPO 2026']);
        $expoJa = ProjectFactory::new()->create(['format' => 'リアル', 'project_name' => 'エキスポ出展']);
        $client = ProjectFactory::new()->create(['format' => 'リアル', 'project_name' => '出展', 'client' => '〇〇EXPO事務局']);

        $this->assertTrue(EventCount::counts($normal));
        $this->assertFalse(EventCount::counts($taiken));
        $this->assertFalse(EventCount::counts($expo));
        $this->assertFalse(EventCount::counts($expoJa));
        $this->assertFalse(EventCount::counts($client), 'クライアント名のEXPOでも外す');

        $this->assertSame('体験会のため', EventCount::autoReason($taiken));
        $this->assertNull(EventCount::autoReason($normal));
    }

    /** 手動指定は自動より優先される。 */
    public function test_manual_setting_wins(): void
    {
        $taikenButCount = ProjectFactory::new()->create(['format' => '体験会', 'count_as_event' => true]);
        $normalButSkip  = ProjectFactory::new()->create(['format' => 'リアル', 'count_as_event' => false]);

        $this->assertTrue(EventCount::counts($taikenButCount), '体験会でも「数える」を選べば数える');
        $this->assertFalse(EventCount::counts($normalButSkip), 'ふつうの案件でも「数えない」を選べば数えない');

        $this->assertSame('数える（手動）', EventCount::label($taikenButCount));
        $this->assertSame('数えない（手動）', EventCount::label($normalButSkip));
        $this->assertSame('数えない（自動・体験会のため）', EventCount::label(
            ProjectFactory::new()->create(['format' => '体験会'])
        ));
    }

    /** 集計ダッシュボードのイベント数が、数えない案件を除いた数になる。 */
    public function test_stats_excludes_uncounted_projects(): void
    {
        $emp = PersonFactory::new()->create(['office' => '東京', 'permission' => 'manager']);

        // 東京で3件（うち体験会1件・EXPO1件）＝数えるのは1件だけ
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->thisMonth(), 'format' => 'リアル', 'project_name' => '水合戦']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->thisMonth(2), 'format' => '体験会', 'project_name' => '体験会']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->thisMonth(3), 'format' => 'リアル', 'project_name' => 'EXPO出展']);

        $data = $this->actingAsPerson($emp)->get('/stats')->assertOk()->original->getData();

        $this->assertSame(1, $data['totalEvents'], '体験会とEXPOは数えない');
        $this->assertSame(2, $data['excludedCount'], '数えなかった件数を画面に出す');
        $this->assertSame(['体験会のため' => 1, 'EXPOを含むため' => 1], $data['excludedReasons']->all());
        // どの案件を数えなかったかも画面に出す
        $this->assertCount(2, $data['excludedList']);
        $this->assertContains('数えない（自動・体験会のため）', $data['excludedList']->pluck('why')->all());
    }

    /** 画面とCSVが実際に出せる（Bladeの構造が壊れていないか）。 */
    public function test_screen_and_csv_render(): void
    {
        $emp = PersonFactory::new()->create(['office' => '東京', 'permission' => 'manager']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->thisMonth(), 'format' => 'リアル']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->thisMonth(2), 'format' => '体験会']);

        $html = $this->actingAsPerson($emp)->get('/stats')->assertOk()->getContent();
        $this->assertStringContainsString('st-excluded', $html, '数えなかった注記が出る');

        $csv = $this->actingAsPerson($emp)->get('/stats/export.csv')->assertOk()->getContent();
        $this->assertStringContainsString('体験会のため', $csv, 'CSVにも理由が残る');
    }

    /** 手動で「数える」にした体験会は、集計にも入る。 */
    public function test_stats_includes_manually_counted_project(): void
    {
        $emp = PersonFactory::new()->create(['office' => '東京', 'permission' => 'manager']);
        ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->thisMonth(), 'format' => '体験会',
            'count_as_event' => true,
        ]);

        $data = $this->actingAsPerson($emp)->get('/stats')->assertOk()->original->getData();

        $this->assertSame(1, $data['totalEvents']);
        $this->assertSame(0, $data['excludedCount']);
    }

    /** 案件登録フォームから3択を保存できる（自動＝null）。 */
    public function test_form_saves_the_three_choices(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);

        foreach ([['auto', null], ['yes', true], ['no', false]] as [$sent, $expected]) {
            $this->actingAsPerson($emp)->post('/project-form', [
                'intent'         => 'publish',
                'content_names'  => '水合戦',
                'start_date'     => $this->thisMonth(),
                'required_count' => '10',
                'count_as_event' => $sent,
            ])->assertSessionHasNoErrors();

            $p = Project::orderByDesc('created_at')->orderByDesc('id')->first();
            $this->assertSame($expected, $p->count_as_event, "count_as_event={$sent} の保存");
        }
    }

    /** 「数えない」に変えたことが案件の編集履歴に残る（「（空）」ではなく「自動」と読める）。 */
    public function test_change_is_recorded_in_history(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $p = ProjectFactory::new()->create(['start_date' => $this->thisMonth()]);

        $this->actingAsPerson($emp);
        $p->count_as_event = false;
        $p->save();

        $this->assertDatabaseHas('project_histories', [
            'project_id'  => $p->id,
            'field'       => 'count_as_event',
            'field_label' => 'イベント数に数える',
            'old_value'   => '自動',
            'new_value'   => '数えない',
        ]);
    }
}
