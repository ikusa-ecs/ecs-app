<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 集計（/stats）の「規模別エントリー数」（2026-10-22 baba要望）。
 *
 * ⚠ 守りたいのは、拠点で絞ったときの数え方（baba決定）。
 *   案件数＝その拠点の案件／人＝本人の所属がその拠点の人（他拠点の案件へのエントリーも数える）。
 */
class StatsEntryScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_by_scale_with_office_rules(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false, 'role' => 'employee',
        ]);
        $tokyo = PersonFactory::new()->create(['name' => '東京の人', 'role' => 'staff', 'office' => '東京']);
        $nagoya = PersonFactory::new()->create(['name' => '名古屋の人', 'role' => 'staff', 'office' => '名古屋']);

        $date = Carbon::today()->startOfMonth()->toDateString();
        $big = ProjectFactory::new()->create(['start_date' => $date, 'status' => '未着手', 'office' => '東京', 'scale' => '大型']);
        $small = ProjectFactory::new()->create(['start_date' => $date, 'status' => '未着手', 'office' => '東京', 'scale' => '']);
        $nagoyaBig = ProjectFactory::new()->create(['start_date' => $date, 'status' => '未着手', 'office' => '名古屋', 'scale' => '大型']);

        Application::create(['project_id' => $big->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);
        Application::create(['project_id' => $small->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);
        Application::create(['project_id' => $nagoyaBig->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);   // 他拠点の案件へのエントリーも数える
        Application::create(['project_id' => $small->id, 'staff_id' => $nagoya->id, 'intent' => '希望']);     // 所属が名古屋なので東京では数えない

        $es = $this->actingAsPerson($admin)->get('/stats?office=東京')->assertOk()
            ->assertSee('規模別エントリー数')->original->getData()['entryScale'];

        $this->assertSame(['小型' => 1, '中型' => 0, '大型' => 1], $es['projects'], '案件数は東京の案件だけ（空の規模は小型）');
        $this->assertSame(['小型' => 1, '中型' => 0, '大型' => 2], $es['entries']);
        $this->assertSame(1, $es['unset']);
        $this->assertCount(1, $es['rows']);
        $this->assertSame('東京の人', $es['rows'][0]['name']);
        $this->assertSame(0, $es['zero']);
    }

    public function test_claude_check_account_is_hidden(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false, 'role' => 'employee',
        ]);
        PersonFactory::new()->create([
            'name' => 'Claude確認用', 'email' => 'onuma+claude@ikusa.co.jp', 'role' => 'employee', 'office' => '東京',
        ]);

        $members = $this->actingAsPerson($admin)->get('/stats?office=東京')->assertOk()->original->getData()['members'];

        $this->assertTrue($members->contains('name', '集計担当'));
        $this->assertFalse($members->contains('name', 'Claude確認用'), '確認用アカウントが集計に出ています。');
    }

    /**
     * エントリー数（合計）が多い順・イベント日の半分以上にエントリーした人はアクティブ（2026-11-09 小沼さん）。
     */
    public function test_sorted_by_total_and_active_staff(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false, 'role' => 'employee',
        ]);
        $many = PersonFactory::new()->create(['name' => 'たくさん', 'role' => 'staff', 'office' => '東京']);
        $few = PersonFactory::new()->create(['name' => 'すこし', 'role' => 'staff', 'office' => '東京']);

        $d = Carbon::today()->startOfMonth();
        $p = [];
        foreach ([0, 0, 1, 2] as $i => $add) {   // イベント日は3日（1日目に2件）
            $p[$i] = ProjectFactory::new()->create(['start_date' => $d->copy()->addDays($add)->toDateString(), 'status' => '未着手', 'office' => '東京', 'scale' => '大型']);
        }
        // たくさん＝4件・3日 → アクティブ／すこし＝同じ日の2件だけ＝1日 → 半分（2日）に届かない
        foreach ($p as $x) {
            Application::create(['project_id' => $x->id, 'staff_id' => $many->id, 'intent' => '希望']);
        }
        Application::create(['project_id' => $p[0]->id, 'staff_id' => $few->id, 'intent' => '希望']);
        Application::create(['project_id' => $p[1]->id, 'staff_id' => $few->id, 'intent' => '希望']);

        $es = $this->actingAsPerson($admin)->get('/stats?office=東京')->assertOk()->original->getData()['entryScale'];

        $this->assertSame(3, $es['eventDays']);
        $this->assertSame(2, $es['activeLine']);
        $this->assertSame(['たくさん', 'すこし'], array_column($es['rows'], 'name'));
        $this->assertSame([3, 1], array_column($es['rows'], 'days'));
        $this->assertSame([true, false], array_column($es['rows'], 'active'));
        $this->assertSame(1, $es['activeCount']);
    }

    /** 外部D＝スタッフでDをしている人の欄（2026-11-09 小沼さん）。 */
    public function test_staff_directors_are_listed(): void
    {
        $admin = PersonFactory::new()->create([
            'name' => '集計担当', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false, 'role' => 'employee',
        ]);
        $ext = PersonFactory::new()->create(['name' => '外部ディレ太', 'role' => 'staff', 'office' => '東京']);
        $p = ProjectFactory::new()->create(['start_date' => Carbon::today()->startOfMonth()->toDateString(), 'status' => '確定', 'office' => '東京']);
        \App\Models\Assignment::create(['project_id' => $p->id, 'staff_id' => $ext->id, 'role' => 'D', 'status' => '確定', 'date' => $p->start_date]);

        $this->actingAsPerson($admin)->get('/stats?office=東京')->assertOk()
            ->assertSee('外部D（スタッフのD）（1名・D 1件／SD 0件）')
            ->assertSee('外部ディレ太');
    }

    /** セールスの新人（入社後半年以内）はセールスに数えず「その他」（2026-11-09 小沼さん）。 */
    public function test_sales_rookie_counts_as_other(): void
    {
        // 判定は「見ている期間のはじまり」で（小沼さん決定）。7/1入社＝2026年8〜12月はその他・2027年1月からセールス。
        $july = PersonFactory::new()->make(['department' => 'セールス', 'hire_date' => Carbon::parse('2026-07-01')]);
        $blank = PersonFactory::new()->make(['department' => 'セールス', 'hire_date' => null]);
        $plan = PersonFactory::new()->make(['department' => 'イベプラ', 'hire_date' => Carbon::parse('2026-07-01')]);

        $c = \App\Http\Controllers\StatsController::class;
        $this->assertSame('その他', $c::statsGroup($july, $c::periodStart('2026-08', 'month')));
        $this->assertSame('その他', $c::statsGroup($july, $c::periodStart('2026-12', 'month')));
        $this->assertSame('セールス', $c::statsGroup($july, $c::periodStart('2027-01', 'month')));
        $this->assertSame('セールス', $c::statsGroup($july, $c::periodStart('2027-Q1', 'quarter')));
        $this->assertSame('その他', $c::statsGroup($july, $c::periodStart('2026', 'year')));
        $this->assertSame('セールス', $c::statsGroup($blank, $c::periodStart('2026-08', 'month')));
        $this->assertSame('イベプラ', $c::statsGroup($plan, $c::periodStart('2026-08', 'month')), 'イベプラの新人はそのまま');
    }
}
