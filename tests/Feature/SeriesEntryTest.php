<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Person;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 連日イベントの「全日まとめてエントリー」（2026-10-16 baba要望＝4日間出られる人を募集したい）。
 *
 * ⚠ 入れるのは「その人の募集中タブに出ている日」だけ。非公開・満員の日は飛ばして知らせる。
 */
class SeriesEntryTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'S-001', 'name' => 'スタッフ', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
    }

    /** 4日間の連日イベント（1日目が根っこ）。 */
    private function fourDays(): array
    {
        $d1 = Carbon::today()->addDays(20);
        $first = ProjectFactory::new()->published()->create([
            'id' => 'P-D1', 'start_date' => $d1->format('Y-m-d'), 'office' => '東京', 'required_count' => 5,
        ]);
        $ids = ['P-D1'];
        for ($i = 2; $i <= 4; $i++) {
            ProjectFactory::new()->published()->create([
                'id' => 'P-D'.$i, 'start_date' => $d1->copy()->addDays($i - 1)->format('Y-m-d'),
                'office' => '東京', 'required_count' => 5, 'parent_project_id' => $first->id,
            ]);
            $ids[] = 'P-D'.$i;
        }

        return $ids;
    }

    public function test_全日程にまとめてエントリーできる(): void
    {
        $me = $this->staff();
        $ids = $this->fourDays();

        // どの日のカードから押しても、全日に入る。
        $this->actingAsPerson($me)->postJson('/staff-portal/entry-series', ['project_id' => 'P-D3'])
            ->assertOk()->assertJson(['ok' => true, 'saved' => true, 'skipped' => []]);

        $this->assertEqualsCanonicalizing($ids, Application::where('staff_id', 'S-001')->pluck('project_id')->all());
    }

    public function test_エントリー済みの日はコメントを消さない(): void
    {
        $me = $this->staff();
        $this->fourDays();
        Application::create(['staff_id' => 'S-001', 'project_id' => 'P-D2', 'intent' => '希望', 'note' => '午後から', 'applied_at' => now()]);

        $this->actingAsPerson($me)->postJson('/staff-portal/entry-series', ['project_id' => 'P-D1'])->assertOk();

        $this->assertSame(4, Application::where('staff_id', 'S-001')->count());
        $this->assertSame('午後から', Application::where('project_id', 'P-D2')->value('note'));
    }

    public function test_非公開と満員の日は飛ばして知らせる(): void
    {
        $me = $this->staff();
        $this->fourDays();
        \App\Models\Project::where('id', 'P-D4')->update(['staff_published' => false]);
        \App\Models\Project::where('id', 'P-D2')->update(['required_count' => 1]);
        Assignment::create(['project_id' => 'P-D2', 'staff_id' => 'S-999', 'role' => 'FC', 'status' => '確定', 'date' => \App\Models\Project::find('P-D2')->start_date]);

        $res = $this->actingAsPerson($me)->postJson('/staff-portal/entry-series', ['project_id' => 'P-D1'])->assertOk();

        $this->assertEqualsCanonicalizing(['P-D1', 'P-D3'], $res->json('applied'));
        $this->assertEqualsCanonicalizing(['P-D2', 'P-D4'], $res->json('skipped'));
        $this->assertEqualsCanonicalizing(['P-D1', 'P-D3'], Application::where('staff_id', 'S-001')->pluck('project_id')->all());
    }

    /** 社員のエントリー一覧でも「全日なのか何日だけなのか」が分かる。 */
    public function test_エントリー一覧に全日か何日だけかが出る(): void
    {
        $this->staff();
        PersonFactory::new()->create([
            'id' => 'S-002', 'name' => '一部だけ', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
        $emp = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '担当', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
        $ids = $this->fourDays();
        foreach ($ids as $pid) {
            Application::create(['staff_id' => 'S-001', 'project_id' => $pid, 'intent' => '希望', 'applied_at' => now()]);
        }
        Application::create(['staff_id' => 'S-002', 'project_id' => 'P-D1', 'intent' => '希望', 'applied_at' => now()]);
        Application::create(['staff_id' => 'S-002', 'project_id' => 'P-D3', 'intent' => '希望', 'applied_at' => now()]);

        $res = $this->actingAsPerson($emp)->get('/entries')->assertOk();
        $case = collect($res->original->getData()['entriesCases'])->firstWhere('id', 'P-D1');

        $this->assertSame(4, $case['series']['total']);
        $byId = collect($case['entrants'])->keyBy('id');
        $this->assertCount(4, $byId['S-001']['seriesOn']);
        $d1 = Carbon::today()->addDays(20);
        $this->assertSame([$d1->format('n/j'), $d1->copy()->addDays(2)->format('n/j')], $byId['S-002']['seriesOn']);
        $res->assertSee('function seriesTag', false)->assertSee('class="m-sr all"', false);
    }

    /** スタッフ公開ボードでも連日イベントと分かる（何日公開したかを出す材料）。 */
    public function test_公開ボードに連日イベントの印が渡る(): void
    {
        $emp = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '担当', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
        $this->fourDays();

        $res = $this->actingAsPerson($emp)->get('/assign-publish')->assertOk();
        $case = collect($res->original->getData()['cases'])->firstWhere('id', 'P-D2');
        $this->assertSame(['idx' => 2, 'total' => 4], array_intersect_key($case['series'], ['idx' => 0, 'total' => 0]));
        $this->assertSame('P-D1', $case['seriesRoot']);
        $res->assertSee('function seriesHtml', false);
    }

    public function test_画面に連日イベントのまとめボタンの材料が渡る(): void
    {
        $me = $this->staff();
        $this->fourDays();

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk()
            ->assertSee('"seriesRoot":"P-D1"', false)
            ->assertSee('function openSeriesModal', false);
    }
}
