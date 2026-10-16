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

    /**
     * 1枚のカードから日を選んでエントリー・取り消しできる（2026-10-16）。
     * リハ日が本番から離れていても（11/24 と 12/29・12/30 のように）同じイベントとして並ぶ。
     */
    public function test_日を選んでエントリーと取り消しができる_リハ日も入る(): void
    {
        $me = $this->staff();
        $d1 = Carbon::today()->addDays(60);
        ProjectFactory::new()->published()->create(['id' => 'P-M1', 'start_date' => $d1->format('Y-m-d'), 'office' => '東京', 'required_count' => 5]);
        ProjectFactory::new()->published()->create(['id' => 'P-M2', 'start_date' => $d1->copy()->addDay()->format('Y-m-d'), 'office' => '東京', 'required_count' => 5, 'parent_project_id' => 'P-M1']);
        ProjectFactory::new()->published()->create(['id' => 'P-R', 'start_date' => $d1->copy()->subDays(35)->format('Y-m-d'), 'office' => '東京', 'required_count' => 5, 'parent_project_id' => 'P-M1', 'date_type' => 'リハ']);
        ProjectFactory::new()->published()->create(['id' => 'P-OTHER', 'start_date' => $d1->format('Y-m-d'), 'office' => '東京']);
        Application::create(['staff_id' => 'S-001', 'project_id' => 'P-M2', 'intent' => '希望', 'applied_at' => now()]);

        // 画面に「同じイベントの全部の日」が開催日順で渡る（リハ日が先頭）。
        $jobs = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData()['recruitJobs'];
        $days = collect($jobs)->firstWhere('id', 'P-M1')['groupDays'];
        $this->assertSame(['P-R', 'P-M1', 'P-M2'], array_column($days, 'id'));
        $this->assertSame('リハ', $days[0]['type']);

        // リハ日と1日目にエントリー・2日目は取り消し。よその案件IDは無視する。
        $this->actingAsPerson($me)->postJson('/staff-portal/entry-series', [
            'project_id' => 'P-M2',
            'apply_ids' => ['P-R', 'P-M1', 'P-OTHER'],
            'cancel_ids' => ['P-M2'],
        ])->assertOk()->assertJson(['saved' => true, 'cancelled' => ['P-M2']]);

        $this->assertEqualsCanonicalizing(['P-R', 'P-M1'], Application::where('staff_id', 'S-001')->pluck('project_id')->all());
    }

    /**
     * 社員側（エントリー一覧・公開ボード）の「全日」はリハ日も含めて数える（スタッフ画面とそろえる・2026-10-16）。
     * 本番2日だけエントリーしてリハに来ない人を「全日」にしない。
     */
    public function test_社員側の全日はリハ日も含めて数える(): void
    {
        $this->staff();
        $emp = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '担当', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
        $d1 = Carbon::today()->addDays(60);
        ProjectFactory::new()->published()->create(['id' => 'P-M1', 'start_date' => $d1->format('Y-m-d'), 'office' => '東京']);
        ProjectFactory::new()->published()->create(['id' => 'P-M2', 'start_date' => $d1->copy()->addDay()->format('Y-m-d'), 'office' => '東京', 'parent_project_id' => 'P-M1']);
        ProjectFactory::new()->create(['id' => 'P-R', 'start_date' => $d1->copy()->subDays(35)->format('Y-m-d'), 'office' => '東京', 'parent_project_id' => 'P-M1', 'date_type' => 'リハ']);
        foreach (['P-M1', 'P-M2'] as $pid) {
            Application::create(['staff_id' => 'S-001', 'project_id' => $pid, 'intent' => '希望', 'applied_at' => now()]);
        }

        $cases = $this->actingAsPerson($emp)->get('/entries')->assertOk()->original->getData()['entriesCases'];
        $case = collect($cases)->firstWhere('id', 'P-M1');
        $this->assertCount(3, $case['groupDays'], 'リハ日が全日の数に入っていない');
        $this->assertCount(2, collect($case['entrants'])->firstWhere('id', 'S-001')['seriesOn']);   // 3日中2日
        $this->assertSame(2, $case['series']['total']);   // 「◯日目／本番◯日」は本番だけ

        $pub = collect($this->actingAsPerson($emp)->get('/assign-publish')->assertOk()->original->getData()['cases'])
            ->firstWhere('id', 'P-M2');
        $this->assertSame(['P-R', 'P-M1', 'P-M2'], array_column($pub['groupDays'], 'id'));
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
