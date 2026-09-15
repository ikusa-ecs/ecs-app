<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Person;
use App\Models\Project;
use App\Support\ProjectSeries;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 連日イベント（同じ案件を何日かに分けて開催）。2026-09-15・FBシート No.16 桑江さん。
 *
 * 【babaの言葉】「連携できるようにしてほしい。できれば案件作成の時に同じ案件は
 *   複数の日程を選択できる。連携してスタッフの画面には同じ案件で複数出れる方を
 *   優先します。みたいな感じにできたらよいかも。」
 *
 * ⚠ 新しい列は作っていない。既存の parent_project_id で結ぶ
 *   （ピックアップ画面がすでにこの形で「◯日目／全◯日」を出していた）。
 *   数え方の正本＝App\Support\ProjectSeries。ここ以外で数え直さない。
 */
class ProjectSeriesTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '登録する人', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    public function test_案件登録で複数日程をまとめて作れる(): void
    {
        $me = $this->manager();
        $d1 = Carbon::today()->addDays(30);

        $this->actingAsPerson($me)->post('/project-form', [
            'client' => 'テスト株式会社',
            'content_names' => '謎解き',
            'required_count' => '10',
            'start_date' => $d1->format('Y-m-d'),
            'office' => '東京',
            'intent' => 'publish',
            'has_series' => '1',
            'series_dates' => [
                $d1->copy()->addDay()->format('Y-m-d'),
                $d1->copy()->addDays(2)->format('Y-m-d'),
            ],
        ])->assertRedirect('/projects');

        $this->assertSame(3, Project::count(), '3日ぶんの案件ができていない');

        $first = Project::whereNull('parent_project_id')->first();
        $rest = Project::where('parent_project_id', $first->id)->get();

        $this->assertCount(2, $rest, '2日目からが1日目に結ばれていない');
        // ⚠ 連日イベントはすべて「本番」。予備日・リハ日・前日設営とは別もの。
        $this->assertTrue($rest->every(fn (Project $p) => $p->date_type === '本番'));
        // ⚠ 公開は1日ずつ担当が行う＝作った時点では全部 非公開。
        $this->assertTrue($rest->every(fn (Project $p) => ! $p->staff_published));
    }

    /** ⚠ 変な日付・同じ日は黙って飛ばす（おかしな案件を増やさない）。 */
    public function test_読めない日付や重複した日は作らない(): void
    {
        $me = $this->manager();
        $d1 = Carbon::today()->addDays(30);

        $this->actingAsPerson($me)->post('/project-form', [
            'client' => 'テスト株式会社',
            'content_names' => '謎解き',
            'required_count' => '10',
            'start_date' => $d1->format('Y-m-d'),
            'office' => '東京',
            'intent' => 'publish',
            'has_series' => '1',
            'series_dates' => [
                '2026-13-40',                     // 実在しない日
                '',                               // 空
                $d1->format('Y-m-d'),             // 1日目と同じ日
                $d1->copy()->addDay()->format('Y-m-d'),
                $d1->copy()->addDay()->format('Y-m-d'),  // 重複
            ],
        ])->assertRedirect('/projects');

        $this->assertSame(2, Project::count(), '飛ばすべき日程まで作っている');
    }

    public function test_何日目かを数えられる(): void
    {
        $d1 = Carbon::today()->addDays(30);
        $p1 = ProjectFactory::new()->create([
            'id' => 'P-1', 'date_type' => '本番', 'start_date' => $d1->format('Y-m-d'),
        ]);
        $p2 = ProjectFactory::new()->create([
            'id' => 'P-2', 'date_type' => '本番', 'parent_project_id' => 'P-1',
            'start_date' => $d1->copy()->addDay()->format('Y-m-d'),
        ]);
        // ⚠ 前日設営は同じ親を持つが「全◯日」には数えない
        //   （本番が2日のイベントで「全3日」と出るとお客様に出す日数と食い違う）。
        ProjectFactory::new()->create([
            'id' => 'P-3', 'date_type' => '前日設営', 'parent_project_id' => 'P-1',
            'start_date' => $d1->copy()->subDay()->format('Y-m-d'),
        ]);

        $groups = ProjectSeries::groupFor([$p1, $p2]);

        $this->assertSame(['idx' => 1, 'total' => 2], collect(ProjectSeries::positionOf($p1, $groups))->only(['idx', 'total'])->all());
        $this->assertSame(['idx' => 2, 'total' => 2], collect(ProjectSeries::positionOf($p2, $groups))->only(['idx', 'total'])->all());
    }

    /** 1日だけの案件は印を出さない（全部に「1日目／全1日」が付くと読みにくい）。 */
    public function test_単発の案件には印を出さない(): void
    {
        $p = ProjectFactory::new()->create(['id' => 'P-SOLO', 'date_type' => '本番']);

        $this->assertNull(ProjectSeries::positionOf($p, ProjectSeries::groupFor([$p])));
        $this->assertFalse(ProjectSeries::isSeries($p));
    }

    /** ほかの日にもエントリーしている人は、おすすめ度が上がる（baba「複数出れる方を優先」）。 */
    public function test_他の日にもエントリーしている人を優先する(): void
    {
        $me = $this->manager();
        $d1 = Carbon::today()->addDays(30);

        $p1 = ProjectFactory::new()->published()->create([
            'id' => 'P-S1', 'date_type' => '本番', 'office' => '東京',
            'start_date' => $d1->format('Y-m-d'), 'required_count' => 3,
        ]);
        ProjectFactory::new()->published()->create([
            'id' => 'P-S2', 'date_type' => '本番', 'office' => '東京', 'parent_project_id' => 'P-S1',
            'start_date' => $d1->copy()->addDay()->format('Y-m-d'), 'required_count' => 3,
        ]);

        $both = PersonFactory::new()->staff()->create(['id' => 'S-201', 'name' => '両日いける子', 'office' => '東京']);
        $one = PersonFactory::new()->staff()->create(['id' => 'S-202', 'name' => '1日だけの子', 'office' => '東京']);

        foreach ([$both, $one] as $s) {
            Application::create(['staff_id' => $s->id, 'project_id' => 'P-S1', 'intent' => '希望']);
        }
        // 両日いける子だけ、2日目にもエントリーしている。
        Application::create(['staff_id' => $both->id, 'project_id' => 'P-S2', 'intent' => '希望']);

        $staff = collect($this->actingAsPerson($me)
            ->get('/project-assign?project=P-S1')->assertOk()->viewData('staff'))->keyBy('id');

        $this->assertGreaterThan(
            $staff['S-202']['score'],
            $staff['S-201']['score'],
            '連日イベントの他の日にもエントリーしている人が優先されていない'
        );
        $this->assertContains('連日イベントの他の日にもエントリー', $staff['S-201']['reasons']);
    }
}
