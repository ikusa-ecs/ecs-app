<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use App\Support\CrossOfficeHelp;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 他拠点の人を自拠点の案件に入れたら、自動で「ヘルプ」として記録する（2026-09-09 baba要望
 * 「日別ボードで他拠点の社員さんを自拠点にアサインするときはヘルプ扱いに自動でしてほしい」）。
 *
 * ⚠ ここで守りたいのは4つ。
 *   ① 拠点が違う人を入れたら、その人の拠点で「ヘルプ」が1件残る。
 *   ② 同じ拠点の人では記録しない（全部ヘルプになると意味がなくなる）。
 *   ③ **すでにある記録（巻き取りなど）を上書きしない。** 巻き取りはヘルプより強い関係。
 *   ④ 拠点が空のときは勘で決めない（何もしない）。
 */
class CrossOfficeHelpTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create([
            'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function tokyoProject()
    {
        return ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'office' => '東京', 'required_count' => 2,
        ]);
    }

    /** 他拠点の社員を入れると「ヘルプ」が記録される。 */
    public function test_assigning_someone_from_another_office_records_help(): void
    {
        $me = $this->admin();
        $p = $this->tokyoProject();
        $helper = PersonFactory::new()->create(['name' => '名古屋の社員', 'office' => '名古屋', 'must_onboard' => false]);

        $res = $this->actingAsPerson($me)->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $helper->id, 'action' => 'assign', 'status' => '仮',
        ])->assertOk();

        $res->assertJson(['ok' => true, 'help_recorded' => true]);
        $this->assertDatabaseHas('project_shares', [
            'project_id' => $p->id, 'office' => '名古屋', 'kind' => 'ヘルプ',
        ]);
    }

    /** ⚠ 同じ拠点の人では記録しない（全部ヘルプになると意味がなくなる）。 */
    public function test_same_office_is_not_recorded(): void
    {
        $me = $this->admin();
        $p = $this->tokyoProject();
        $mate = PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($me)->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $mate->id, 'action' => 'assign', 'status' => '仮',
        ])->assertOk()->assertJson(['help_recorded' => false]);

        $this->assertSame(0, ProjectShare::where('project_id', $p->id)->count());
    }

    /** ⚠ すでにある「巻き取り」を、あとからヘルプで塗りつぶさない。 */
    public function test_it_does_not_overwrite_an_existing_share(): void
    {
        $me = $this->admin();
        $p = $this->tokyoProject();
        ProjectShare::create(['project_id' => $p->id, 'office' => '大阪', 'kind' => '巻き取り']);
        $helper = PersonFactory::new()->create(['office' => '大阪', 'must_onboard' => false]);

        $this->actingAsPerson($me)->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $helper->id, 'action' => 'assign', 'status' => '仮',
        ])->assertOk()->assertJson(['help_recorded' => false]);

        $this->assertDatabaseHas('project_shares', [
            'project_id' => $p->id, 'office' => '大阪', 'kind' => '巻き取り',
        ]);
    }

    /** ⚠ 拠点が空のときは勘で決めない。 */
    public function test_blank_office_is_left_alone(): void
    {
        $p = $this->tokyoProject();
        $noOffice = PersonFactory::new()->create(['office' => null, 'must_onboard' => false]);

        $this->assertFalse(CrossOfficeHelp::record($p, $noOffice));
        $this->assertSame(0, ProjectShare::where('project_id', $p->id)->count());
    }

    /** アサインを外しても、手伝ってもらった記録は消さない。 */
    public function test_unassigning_keeps_the_record(): void
    {
        $me = $this->admin();
        $p = $this->tokyoProject();
        $helper = PersonFactory::new()->create(['office' => '名古屋', 'must_onboard' => false]);

        $this->actingAsPerson($me)->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $helper->id, 'action' => 'assign', 'status' => '仮',
        ])->assertOk();
        $this->actingAsPerson($me)->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $helper->id, 'action' => 'unassign',
        ])->assertOk();

        $this->assertDatabaseHas('project_shares', [
            'project_id' => $p->id, 'office' => '名古屋', 'kind' => 'ヘルプ',
        ]);
    }
}
