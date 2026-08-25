<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Support\OfficeSettings;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 拠点で見える範囲を絞る・第2弾（2026-08-05 baba確定）。
 *
 * 対象＝日別ボード（/assign）・エントリー一覧（/entries）・ピックアップ（/pickup）・
 *       公開ボード（/assign-publish）＋スタッフ画面の募集中タブ。
 *
 * 方針（第1弾と同じ）：
 *  ・一般社員／スタッフ … 自拠点だけ。管理者以上 … 既定は全拠点・?office= で切替
 *  ・案件は絞るが、その案件に紐づく人（応募者・現メンバー）は他拠点でも残す
 *    （消えると保存＝上書きで担当が外れる／応募を取り消せなくなる）
 */
class OfficeScopeBoardTest extends TestCase
{
    use RefreshDatabase;

    /** 近い将来の日付（日別ボードは基準日〜21日先だけを出すため）。 */
    private function soon(int $plusDays = 3): string
    {
        return Carbon::today()->addDays($plusDays)->format('Y-m-d');
    }

    /** 4画面すべて、一般社員には自拠点の案件だけが出る。 */
    public function test_board_screens_show_only_own_office_for_employee(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon(4)]);

        foreach ([
            '/assign' => 'boardCases',
            '/entries' => 'entriesCases',
            '/pickup' => 'pickupCases',
            '/assign-publish' => 'cases',
        ] as $url => $key) {
            $data = $this->actingAsPerson($tokyoEmp)->get($url)
                ->assertOk("画面 {$url} が開けませんでした")
                ->original->getData();

            $this->assertSame('東京', $data['officeScope'], "{$url} は自拠点で固定される");
            $this->assertSame(
                [$tokyo->id],
                collect($data[$key])->pluck('id')->all(),
                "{$url} に他拠点の案件が出てしまっている"
            );
        }
    }

    /** 管理者は既定で全拠点。?office= を付けたときだけ絞られる（日別ボード）。 */
    public function test_board_screens_let_manager_switch_office(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '東京']);
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        $osaka = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon(4)]);

        $all = $this->actingAsPerson($manager)->get('/assign')
            ->assertOk()->original->getData();
        $this->assertNull($all['officeScope']);
        $this->assertEqualsCanonicalizing(
            [$tokyo->id, $osaka->id],
            collect($all['boardCases'])->pluck('id')->all()
        );

        $only = $this->actingAsPerson($manager)->get('/assign?office=' . urlencode('大阪'))
            ->assertOk()->original->getData();
        $this->assertSame('大阪', $only['officeScope']);
        $this->assertSame([$osaka->id], collect($only['boardCases'])->pluck('id')->all());
    }

    /**
     * 公開ボードだけは「必ず1拠点」（2026-08-21 baba）。
     * 全拠点をまとめて表示すると、一括公開で他拠点の未公開案件まで
     * スタッフに出てしまう事故が起きるため、管理者でも「全拠点」にはならない。
     */
    public function test_publish_board_is_always_one_office(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '東京']);
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        $osaka = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon(4)]);

        // 指定なし＝自分の拠点だけ
        $mine = $this->actingAsPerson($manager)->get('/assign-publish')
            ->assertOk()->original->getData();
        $this->assertSame('東京', $mine['officeScope']);
        $this->assertSame([$tokyo->id], collect($mine['cases'])->pluck('id')->all());

        // 空指定（?office=）でも全拠点にはしない
        $blank = $this->actingAsPerson($manager)->get('/assign-publish?office=')
            ->assertOk()->original->getData();
        $this->assertSame('東京', $blank['officeScope']);

        // 切り替えれば、その拠点だけ
        $only = $this->actingAsPerson($manager)->get('/assign-publish?office=' . urlencode('大阪'))
            ->assertOk()->original->getData();
        $this->assertSame('大阪', $only['officeScope']);
        $this->assertSame([$osaka->id], collect($only['cases'])->pluck('id')->all());
    }

    /** 一括公開は、画面で見ていた拠点の案件だけを公開する（取り違えの保険）。 */
    public function test_bulk_publish_skips_other_offices(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '東京']);
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        $osaka = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon(4)]);

        $this->actingAsPerson($manager)->postJson('/assign-publish/set', [
            'ids'     => [$tokyo->id, $osaka->id],
            'publish' => true,
            'office'  => '東京',
        ])->assertOk();

        $this->assertTrue((bool) $tokyo->fresh()->staff_published);
        $this->assertFalse((bool) $osaka->fresh()->staff_published, '見ていない拠点の案件は公開しない');
    }

    /** ピックアップ：案件は絞るが、その案件のメンバー（他拠点のヘルプ）は残す。 */
    public function test_pickup_keeps_members_from_other_offices(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $osakaHelper = PersonFactory::new()->staff()->create(['office' => '大阪']);
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $osakaHelper->id,
            'date' => $this->soon(), 'role' => 'OP', 'status' => '確定',
        ]);

        $cases = collect(
            $this->actingAsPerson($tokyoEmp)->get('/pickup')->assertOk()->original->getData()['pickupCases']
        );

        $members = collect($cases->firstWhere('id', $project->id)['members'])->pluck('id')->all();
        $this->assertContains($osakaHelper->id, $members, '他拠点のメンバーが消えると保存で担当が外れてしまう');
    }

    /** エントリー一覧：他拠点のスタッフの応募も、自拠点の案件なら見える。 */
    public function test_entries_keeps_applicants_from_other_offices(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $osakaStaff = PersonFactory::new()->staff()->create(['office' => '大阪']);
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);
        Application::create([
            'project_id' => $project->id, 'staff_id' => $osakaStaff->id,
            'intent' => '希望', 'applied_at' => now(),
        ]);

        $cases = collect(
            $this->actingAsPerson($tokyoEmp)->get('/entries')->assertOk()->original->getData()['entriesCases']
        );

        $entrants = collect($cases->firstWhere('id', $project->id)['entrants'])->pluck('id')->all();
        $this->assertContains($osakaStaff->id, $entrants, '応募は本人の意思表示なので隠さない');
    }

    /** 日別ボードの「希望者（その日稼働可）」は自拠点のスタッフだけ。 */
    public function test_board_available_pool_is_limited_to_my_office(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $tokyoStaff = PersonFactory::new()->staff()->create(['office' => '東京']);
        $osakaStaff = PersonFactory::new()->staff()->create(['office' => '大阪']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);

        foreach ([$tokyoStaff, $osakaStaff] as $s) {
            \App\Models\ShiftPreference::create([
                'staff_id' => $s->id, 'date' => $this->soon(), 'availability' => '稼働可',
                'period' => Carbon::today()->addDays(3)->format('Y-m'),
            ]);
        }

        $data = $this->actingAsPerson($tokyoEmp)->get('/assign')->assertOk()->original->getData();
        $poolIds = collect($data['boardAvail'])->flatten(1)->pluck('id')->unique()->all();

        $this->assertContains($tokyoStaff->id, $poolIds);
        $this->assertNotContains($osakaStaff->id, $poolIds);
    }

    /**
     * 社員・D集計（/projects-agg）＝拠点別。
     * 絞るのは「社員の所属拠点」だけで、数える案件は絞らない（他拠点への応援も数える）。
     */
    public function test_projects_agg_filters_by_employee_office_but_counts_all_projects(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京', 'name' => '東京の社員']);
        $osakaEmp = PersonFactory::new()->create(['office' => '大阪', 'name' => '大阪の社員']);

        // 東京の社員が「大阪の案件」でDを務めた＝他拠点への応援。これも数える。
        $osakaProject = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);
        Assignment::create([
            'project_id' => $osakaProject->id, 'staff_id' => $tokyoEmp->id,
            'date' => $this->soon(), 'role' => 'D', 'status' => '仮',
        ]);

        $rows = collect(
            $this->actingAsPerson($tokyoEmp)->get('/projects-agg')->assertOk()->original->getData()['rows']
        );

        $this->assertSame(['東京の社員'], $rows->pluck('name')->all(), '他拠点の社員は並べない');
        $this->assertSame(1, $rows->firstWhere('name', '東京の社員')['d'], '他拠点への応援も数える');
        $this->assertNotContains($osakaEmp->name, $rows->pluck('name')->all());
    }

    /** スタッフ画面の募集中タブ＝自分の拠点の募集だけ。ただし応募済みの案件は残す。 */
    public function test_staff_portal_recruiting_tab_is_limited_to_my_office(): void
    {
        // ※募集タブに出るのは「公開ボードで公開ON（staff_published）」の案件だけ。
        //   応募済みの案件は非公開でも残す（取り消せなくなるため）＝ここは公開ONにしない。
        $tokyoStaff = PersonFactory::new()->staff()->create(['office' => '東京']);
        $tokyoJob = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'is_recruiting' => true,
            'staff_published' => true,
        ]);
        $osakaJob = ProjectFactory::new()->create([
            'office' => '大阪', 'start_date' => $this->soon(4), 'is_recruiting' => true,
            'staff_published' => true,
        ]);
        $osakaApplied = ProjectFactory::new()->create([
            'office' => '大阪', 'start_date' => $this->soon(5), 'is_recruiting' => true,
        ]);
        Application::create([
            'project_id' => $osakaApplied->id, 'staff_id' => $tokyoStaff->id,
            'intent' => '希望', 'applied_at' => now(),
        ]);

        $ids = collect(
            $this->actingAsPerson($tokyoStaff)->get('/staff-portal')->assertOk()->original->getData()['recruitJobs']
        )->pluck('id')->all();

        $this->assertContains($tokyoJob->id, $ids);
        $this->assertContains($osakaApplied->id, $ids, '応募済みの案件は他拠点でも残す');
        $this->assertNotContains($osakaJob->id, $ids, '関係のない他拠点の募集は出さない');
    }

    /**
     * スタッフ画面のお知らせ文は、その人の拠点のものが出る（2026-08-25 baba要望）。
     * ⚠ 以前は全国共通で、東京で直すと東北のスタッフの画面まで変わっていた。
     */
    public function test_staff_sees_notice_of_own_office(): void
    {
        OfficeSettings::put(OfficeSettings::NOTICE, '東京', '東京のお知らせです。');
        OfficeSettings::put(OfficeSettings::NOTICE, '東北', '東北のお知らせです。');

        $tokyo = PersonFactory::new()->create(['id' => 'S-901', 'role' => 'staff', 'permission' => 'staff', 'office' => '東京', 'must_onboard' => false]);
        $tohoku = PersonFactory::new()->create(['id' => 'S-902', 'role' => 'staff', 'permission' => 'staff', 'office' => '東北', 'must_onboard' => false]);

        $this->actingAsPerson($tokyo)->get('/staff-portal')->assertOk()
            ->assertViewHas('notice', '東京のお知らせです。');
        $this->actingAsPerson($tohoku)->get('/staff-portal')->assertOk()
            ->assertViewHas('notice', '東北のお知らせです。');
    }

    /** 通常案件の締切日も、その人の拠点のものが出る。 */
    public function test_staff_sees_deadline_of_own_office(): void
    {
        OfficeSettings::put(OfficeSettings::DEADLINE, '東京', '2026-09-10');
        OfficeSettings::put(OfficeSettings::DEADLINE, '東北', '2026-09-20');

        ProjectFactory::new()->create([
            'office' => '東北', 'start_date' => $this->soon(10),
            'staff_published' => true, 'is_recruiting' => true, 'category' => '通常案件',
        ]);

        $tohoku = PersonFactory::new()->create(['id' => 'S-903', 'role' => 'staff', 'permission' => 'staff', 'office' => '東北', 'must_onboard' => false]);
        $jobs = $this->actingAsPerson($tohoku)->get('/staff-portal')->assertOk()
            ->viewData('recruitJobs');

        $this->assertNotEmpty($jobs, '東北のスタッフに東北の募集が出ること');
        $this->assertStringContainsString('9/20', (string) $jobs->first()['deadline']);
    }
}
