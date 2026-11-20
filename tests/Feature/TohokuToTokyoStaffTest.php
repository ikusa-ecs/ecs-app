<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ProjectShare;
use App\Models\Setting;
use App\Support\OfficeSettings;
use App\Support\StaffOfficeReach;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「東北の案件は、いつでも東京のスタッフにも出す」（2026-11-20 小沼さん決定・正本＝App\Support\StaffOfficeReach）。
 *
 *  ・東北で公開中の東北の案件は、公開ボードで共有しなくても東京のスタッフの募集中タブに出る。
 *  ・公開前・非公開は出さない。逆向き（東京→東北）と他拠点（札幌など）には広げない。
 *  ・東京のスタッフのエントリーは保存でき、東北の担当のエントリー一覧・日別ボード・ピックアップ・案件別アサインに出る。
 */
class TohokuToTokyoStaffTest extends TestCase
{
    use RefreshDatabase;

    private function soon(int $plusDays = 5): string
    {
        return Carbon::today()->addDays($plusDays)->format('Y-m-d');
    }

    private function staffIn(string $office)
    {
        return PersonFactory::new()->staff()->create(['office' => $office, 'must_onboard' => false, 'active' => true]);
    }

    private function recruitIds($me): array
    {
        return collect($this->actingAsPerson($me)->get('/staff-portal')->assertOk()
            ->original->getData()['recruitJobs'])->pluck('id')->all();
    }

    public function test_組は1か所にまとまっていて東京から東北だけ(): void
    {
        $this->assertSame(['東北' => ['東京']], StaffOfficeReach::REACH);
        $this->assertSame(['東北'], StaffOfficeReach::sourcesFor('東京'));
        $this->assertSame([], StaffOfficeReach::sourcesFor('東北'), '逆向き（東京の案件を東北に）は広げない');
        $this->assertSame([], StaffOfficeReach::sourcesFor('札幌'));
        $this->assertSame([], StaffOfficeReach::sourcesFor(null));
    }

    public function test_東北で公開中の東北の案件は東京のスタッフにも出る(): void
    {
        $tokyo = $this->staffIn('東京');
        // 公開ボードで「東北」で公開した形（拠点ごとの公開）。
        $perOffice = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $this->soon(), 'published_offices' => ['東北'],
        ]);
        // 9/30より前の形（published_offices が null＝関わる全拠点）。
        $legacy = ProjectFactory::new()->published()->create(['office' => '東北', 'start_date' => $this->soon(6)]);
        // 公開前。
        $notYet = ProjectFactory::new()->create(['office' => '東北', 'start_date' => $this->soon(7)]);
        // 一度公開して非公開に戻した（拠点の一覧が空）。
        $closed = ProjectFactory::new()->create([
            'office' => '東北', 'start_date' => $this->soon(8), 'staff_published' => false, 'published_offices' => [],
        ]);
        // 東北のスタッフには出していない（名古屋だけで公開・名古屋に共有）＝東京にも出さない。
        $elsewhere = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $this->soon(9), 'published_offices' => ['名古屋'],
        ]);
        ProjectShare::create(['project_id' => $elsewhere->id, 'office' => '名古屋', 'kind' => 'ヘルプ']);

        $ids = $this->recruitIds($tokyo);
        $this->assertContains($perOffice->id, $ids);
        $this->assertContains($legacy->id, $ids);
        $this->assertNotContains($notYet->id, $ids, '公開前の東北の案件は出さない');
        $this->assertNotContains($closed->id, $ids, '非公開の東北の案件は出さない');
        $this->assertNotContains($elsewhere->id, $ids, '東北のスタッフに出ていない案件は東京にも出さない');

        // 「東北の案件のみ」の絞り込みに使う拠点の印も付いている。
        $job = collect($this->actingAsPerson($tokyo)->get('/staff-portal')->original->getData()['recruitJobs'])
            ->firstWhere('id', $perOffice->id);
        $this->assertSame('東北', $job['office']);
    }

    public function test_逆向きと他拠点には広げない(): void
    {
        $tohoku = $this->staffIn('東北');
        $sapporo = $this->staffIn('札幌');
        $tokyo = $this->staffIn('東京');
        $tokyoProject = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon()]);
        $tohokuProject = ProjectFactory::new()->published()->create(['office' => '東北', 'start_date' => $this->soon()]);
        $sapporoProject = ProjectFactory::new()->published()->create(['office' => '札幌', 'start_date' => $this->soon()]);
        // 東京に共有されて東北でも公開中の、東京の案件（東北に共有）＝東京の案件なので東京の決まりのまま。
        $tokyoSharedToTohoku = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'published_offices' => ['東北'],
        ]);
        ProjectShare::create(['project_id' => $tokyoSharedToTohoku->id, 'office' => '東北', 'kind' => 'ヘルプ']);

        $this->assertNotContains($tokyoProject->id, $this->recruitIds($tohoku), '東京の案件を東北のスタッフには出さない');
        $this->assertContains($tohokuProject->id, $this->recruitIds($tohoku), '東北のスタッフには今までどおり出る');
        $this->assertNotContains($tohokuProject->id, $this->recruitIds($sapporo), '札幌には広げない');
        $this->assertNotContains($sapporoProject->id, $this->recruitIds($tokyo), '札幌の案件は東京に出さない');
        $this->assertNotContains($tokyoSharedToTohoku->id, $this->recruitIds($tokyo), '東北でだけ公開した東京の案件は今までどおり出さない');
    }

    public function test_東京のスタッフは東北の案件にエントリーを保存できる(): void
    {
        $tokyo = $this->staffIn('東京');
        $p = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $this->soon(), 'published_offices' => ['東北'], 'required_count' => 5,
        ]);

        $this->actingAsPerson($tokyo)->postJson('/staff-portal/entry', ['project_id' => $p->id])
            ->assertOk()->assertJson(['ok' => true, 'saved' => true, 'applied' => true]);
        $this->assertTrue(Application::where('staff_id', $tokyo->id)->where('project_id', $p->id)->exists());

        // 開き直しても「エントリー済み」のまま（一覧と保存がずれていない）。
        $job = collect($this->actingAsPerson($tokyo)->get('/staff-portal')->original->getData()['recruitJobs'])
            ->firstWhere('id', $p->id);
        $this->assertTrue($job['applied']);
    }

    public function test_まとめてエントリーの門も同じ決まり(): void
    {
        $tokyo = $this->staffIn('東京');
        $d1 = Carbon::today()->addDays(20);
        $first = ProjectFactory::new()->published()->create([
            'id' => 'P-T1', 'office' => '東北', 'start_date' => $d1->format('Y-m-d'), 'published_offices' => ['東北'], 'required_count' => 5,
        ]);
        ProjectFactory::new()->published()->create([
            'id' => 'P-T2', 'office' => '東北', 'start_date' => $d1->copy()->addDay()->format('Y-m-d'),
            'published_offices' => ['東北'], 'required_count' => 5, 'parent_project_id' => $first->id,
        ]);
        // 3日目は東北で非公開＝飛ばす。
        ProjectFactory::new()->create([
            'id' => 'P-T3', 'office' => '東北', 'start_date' => $d1->copy()->addDays(2)->format('Y-m-d'),
            'required_count' => 5, 'parent_project_id' => $first->id,
        ]);

        $res = $this->actingAsPerson($tokyo)->postJson('/staff-portal/entry-series', ['project_id' => 'P-T1'])->assertOk();
        $this->assertEqualsCanonicalizing(['P-T1', 'P-T2'], $res->json('applied'));
        $this->assertEqualsCanonicalizing(['P-T3'], $res->json('skipped'));
    }

    public function test_東北の担当には東京のスタッフのエントリーが出る(): void
    {
        $tohokuEmp = PersonFactory::new()->create(['office' => '東北']);
        $tokyoStaff = $this->staffIn('東京');
        $tohokuStaff = $this->staffIn('東北');
        $nagoyaStaff = $this->staffIn('名古屋');
        $p = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $this->soon(3), 'published_offices' => ['東北'],
        ]);
        foreach ([$tokyoStaff, $tohokuStaff, $nagoyaStaff] as $s) {
            Application::create(['project_id' => $p->id, 'staff_id' => $s->id, 'intent' => '希望', 'applied_at' => now()]);
        }

        // エントリー一覧（案件タブ）と新着タブ。
        $data = $this->actingAsPerson($tohokuEmp)->get('/entries')->assertOk()->original->getData();
        $entrants = collect(collect($data['entriesCases'])->firstWhere('id', $p->id)['entrants'])->pluck('id')->all();
        $this->assertContains($tokyoStaff->id, $entrants, '東北の案件にエントリーした東京のスタッフは東北の担当に見える');
        $this->assertContains($tohokuStaff->id, $entrants);
        $this->assertNotContains($nagoyaStaff->id, $entrants, '名古屋のスタッフは今までどおり出さない');
        $feedIds = collect($data['feedRows'])->pluck('staffId')->all();
        $this->assertContains($tokyoStaff->id, $feedIds);
        $this->assertNotContains($nagoyaStaff->id, $feedIds);

        // 日別ボードの希望者・ピックアップ。
        $board = collect($this->actingAsPerson($tohokuEmp)->get('/assign')->assertOk()->original->getData()['boardCases']);
        $applicants = collect($board->firstWhere('id', $p->id)['applicants'])->pluck('id')->all();
        $this->assertContains($tokyoStaff->id, $applicants);
        $this->assertNotContains($nagoyaStaff->id, $applicants);
        $pickup = collect($this->actingAsPerson($tohokuEmp)->get('/pickup')->assertOk()->original->getData()['pickupCases']);
        $pe = collect($pickup->firstWhere('id', $p->id)['entrants'])->pluck('id')->all();
        $this->assertContains($tokyoStaff->id, $pe);
        $this->assertNotContains($nagoyaStaff->id, $pe);

        // 案件別アサインの名簿にも出て、候補（エントリーした人）になる。
        $staff = collect($this->actingAsPerson($tohokuEmp)->get('/project-assign?project='.$p->id)->assertOk()->viewData('staff'));
        $row = $staff->firstWhere('id', $tokyoStaff->id);
        $this->assertNotNull($row, '東京のスタッフが東北の案件の名簿に出る');
        $this->assertTrue($row['entry']);
        $this->assertTrue($row['eligible']);
        $this->assertNull($staff->firstWhere('id', $nagoyaStaff->id), '名古屋のスタッフは名簿に出さない');
    }

    public function test_東北に共有された東京の案件では東京のスタッフの応募を東北に出さない(): void
    {
        // 2026-10-07 の決まり（他拠点に頼むと、その拠点のスタッフの応募がこちらに混ざる）は東北の案件以外ではそのまま。
        $tohokuEmp = PersonFactory::new()->create(['office' => '東北']);
        $tokyoStaff = $this->staffIn('東京');
        $p = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon(3)]);
        ProjectShare::create(['project_id' => $p->id, 'office' => '東北', 'kind' => 'ヘルプ']);
        Application::create(['project_id' => $p->id, 'staff_id' => $tokyoStaff->id, 'intent' => '希望', 'applied_at' => now()]);

        $data = $this->actingAsPerson($tohokuEmp)->get('/entries')->assertOk()->original->getData();
        $entrants = collect(collect($data['entriesCases'])->firstWhere('id', $p->id)['entrants'])->pluck('id')->all();
        $this->assertNotContains($tokyoStaff->id, $entrants);
    }

    public function test_カレンダーの件数と締切も東北の案件にそろう(): void
    {
        $tokyo = $this->staffIn('東京');
        $day = Carbon::today()->startOfMonth()->addDays(14);
        $period = $day->format('Y-m');
        ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $day->format('Y-m-d'), 'published_offices' => ['東北'], 'yomi' => '確定',
        ]);
        // 公開前の東北の案件は東京のスタッフの件数に入れない（画面に出ていないので）。
        ProjectFactory::new()->create(['office' => '東北', 'start_date' => $day->format('Y-m-d'), 'yomi' => '確定']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $day->format('Y-m-d'), 'yomi' => '確定']);

        $counts = $this->actingAsPerson($tokyo)->get('/staff-portal?period='.$period)->assertOk()->original->getData()['dayEventCounts'];
        $this->assertSame(2, $counts[(int) $day->day] ?? 0, '東京1件＋東北で公開中の東北1件');

        // 締切は東北の一斉締切を出す（東北の担当が決めるため）。
        Setting::put(OfficeSettings::key(OfficeSettings::DEADLINE, '東北'), Carbon::today()->addDays(10)->format('Y-m-d'));
        Setting::put(OfficeSettings::key(OfficeSettings::DEADLINE, '東京'), Carbon::today()->addDays(2)->format('Y-m-d'));
        $p = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $this->soon(20), 'published_offices' => ['東北'],
        ]);
        $job = collect($this->actingAsPerson($tokyo)->get('/staff-portal')->original->getData()['recruitJobs'])->firstWhere('id', $p->id);
        $this->assertSame(Carbon::today()->addDays(10)->format('n/j'), $job['deadline']);
    }

    public function test_案件カードの拠点の印の材料が画面に渡る(): void
    {
        // 印は「案件の拠点 ≠ 本人の拠点」のときだけ出す。材料＝本人の拠点と、各案件の office が画面に渡ること。
        $tokyo = $this->staffIn('東京');
        $p = ProjectFactory::new()->published()->create([
            'office' => '東北', 'start_date' => $this->soon(), 'published_offices' => ['東北'],
        ]);
        $res = $this->actingAsPerson($tokyo)->get('/staff-portal')->assertOk();
        $this->assertSame('東京', $res->original->getData()['myOffice']);
        $res->assertSee('window.ECS_MY_OFFICE = ', false);
        $res->assertSee('tag-mini office', false);
        $job = collect($res->original->getData()['recruitJobs'])->firstWhere('id', $p->id);
        $this->assertSame('東北', $job['office']);
    }
}
