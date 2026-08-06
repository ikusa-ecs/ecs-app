<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectFinance;
use App\Support\FinanceItems;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 収支一覧（/finance-list）と、収支の入力権限（2026-08-06 baba確定）のテスト。
 *
 * 決まっているルール：
 *  ・見る＝社員以上は全案件の収支を見られる／スタッフは入れない
 *  ・直す＝担当のD／営業担当と管理者以上だけ
 *  ・締切＝イベント終了後3営業日（土日を飛ばす）
 *  ・経費の合計＝単価×数量、実費は1,000円単位に切り上げ（入力画面と同じ計算）
 */
class FinanceListTest extends TestCase
{
    use RefreshDatabase;

    /** 経費の合計＝単価×数量＋実費（1,000円単位に切り上げ）。入力画面のJSと同じ計算になる。 */
    public function test_cost_total_matches_the_input_screen_rule(): void
    {
        $items = [
            'staff_real' => ['qty' => 3, 'amount' => 30000],   // 単価10,000 × 3名 = 30,000
            'food' => ['qty' => 2, 'amount' => 2000],          // 単価1,000 × 2人 = 2,000
            'highway' => ['amount' => 4300],                   // 実費 → 5,000（切り上げ）
            'outsource' => ['amount' => 1],                    // 実費 → 1,000（切り上げ）
        ];

        $this->assertSame(38000, FinanceItems::costTotal($items));
        $this->assertSame(12000, FinanceItems::profit(50000, $items));
        $this->assertTrue(FinanceItems::isFilled(null, $items));
        $this->assertFalse(FinanceItems::isFilled(null, []));
    }

    /** 数量は保存された金額ではなく「単価×数量」で数え直す（単価を直したら一覧も揃う）。 */
    public function test_cost_total_recalculates_from_quantity(): void
    {
        // amount が古い単価で保存されていても、今の単価で計算し直す。
        $this->assertSame(10000, FinanceItems::costTotal(['staff_real' => ['qty' => 1, 'amount' => 999]]));
    }

    /** 一覧は社員が開けて、売上・経費・利益・入力状態が出る。 */
    public function test_finance_list_shows_totals_for_the_month(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $date = Carbon::today()->addDays(2)->format('Y-m-d');

        $filled = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $date, 'project_name' => '入力済み案件',
        ]);
        $empty = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $date, 'project_name' => '未入力案件',
        ]);

        ProjectFinance::create([
            'project_id' => $filled->id,
            'revenue' => 500000,
            'items' => ['staff_real' => ['qty' => 5], 'highway' => ['amount' => 2100]],
            'updated_by' => $employee->id,
        ]);

        $data = $this->actingAsPerson($employee)
            ->get('/finance-list?month=' . Carbon::parse($date)->format('Y-m'))
            ->assertOk()
            ->original->getData();

        $this->assertSame(2, $data['summary']['count']);
        $this->assertSame(1, $data['summary']['filled']);
        $this->assertSame(1, $data['summary']['unfilled']);
        $this->assertSame(500000, $data['summary']['revenue']);
        // 経費＝10,000×5 ＋ 実費2,100→3,000 ＝ 53,000
        $this->assertSame(53000, $data['summary']['cost']);
        $this->assertSame(447000, $data['summary']['profit']);

        $rows = collect($data['rows']);
        $this->assertTrue($rows->firstWhere('id', $filled->id)['filled']);
        $this->assertFalse($rows->firstWhere('id', $empty->id)['filled']);
        $this->assertSame($employee->name, $rows->firstWhere('id', $filled->id)['updatedBy']);
    }

    /** 締切＝イベント終了後3営業日（土日は飛ばす）。過ぎて未入力なら「遅れ」になる。 */
    public function test_deadline_skips_weekends_and_marks_overdue(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);

        // 金曜開催 → 3営業日後は翌週の水曜（土日を飛ばす）。
        $friday = Carbon::today()->startOfMonth()->next(Carbon::FRIDAY);
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $friday->format('Y-m-d')]);

        $rows = collect(
            $this->actingAsPerson($employee)
                ->get('/finance-list?month=' . $friday->format('Y-m'))
                ->assertOk()->original->getData()['rows']
        );
        $row = $rows->firstWhere('id', $project->id);

        $this->assertSame(
            $friday->copy()->addDays(5)->format('Y-m-d'),   // 金→翌週水＝5日後
            $row['deadline'],
            '土日を飛ばして3営業日後になっていない'
        );
        // 過去の金曜なら締切も過ぎている＝未入力は「遅れ」。
        if (Carbon::today()->gt(Carbon::parse($row['deadline']))) {
            $this->assertTrue($row['overdue']);
        }
    }

    /** 担当（D・営業）でない社員は入力できない。担当と管理者はできる。 */
    public function test_only_owners_and_managers_can_save(): void
    {
        $date = Carbon::today()->addDays(3)->format('Y-m-d');
        $project = ProjectFactory::new()->create(['start_date' => $date, 'sales_owners' => ['営業 太郎']]);

        $other = PersonFactory::new()->create(['name' => '無関係 社員']);
        $director = PersonFactory::new()->create(['name' => 'D 社員']);
        $sales = PersonFactory::new()->create(['name' => '営業 太郎']);
        $manager = PersonFactory::new()->manager()->create(['name' => '管理者']);

        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $director->id,
            'date' => $date, 'role' => 'D', 'status' => '仮',
        ]);

        $payload = ['project_id' => $project->id, 'revenue' => 100000, 'items' => [], 'memo' => ''];

        // 関係のない社員＝403（保存されない）
        $this->actingAsPerson($other)->postJson('/mypage-finance/save', $payload)->assertStatus(403);
        $this->assertSame(0, ProjectFinance::count());

        // D・営業担当・管理者＝保存できる
        foreach ([$director, $sales, $manager] as $who) {
            $this->actingAsPerson($who)->postJson('/mypage-finance/save', $payload)
                ->assertOk()->assertJson(['ok' => true]);
        }

        // 案件1件＝1行のまま（上書き）。最後に保存した人が記録される。
        $this->assertSame(1, ProjectFinance::count());
        $this->assertSame($manager->id, ProjectFinance::first()->updated_by);
    }

    /** スタッフは収支一覧に入れない（自分のスタッフ画面へ戻される）。 */
    public function test_staff_cannot_open_finance_list(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/finance-list')->assertRedirect('/staff-portal');
    }

    /** 拠点で絞る（一般社員は自拠点だけ）＝他の画面と同じ扱い。 */
    public function test_finance_list_is_scoped_by_office(): void
    {
        $tokyoEmp = PersonFactory::new()->create(['office' => '東京']);
        $date = Carbon::today()->addDays(4)->format('Y-m-d');
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);
        ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $date]);

        $data = $this->actingAsPerson($tokyoEmp)
            ->get('/finance-list?month=' . Carbon::parse($date)->format('Y-m'))
            ->assertOk()->original->getData();

        $this->assertSame('東京', $data['officeScope']);
        $this->assertSame([$tokyo->id], collect($data['rows'])->pluck('id')->all());
    }

    /** CSVで書き出せる（Excel・Salesforceへの登録用）。 */
    public function test_csv_export_contains_the_month_rows(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $date, 'client' => 'テスト商事',
        ]);
        ProjectFinance::create(['project_id' => $project->id, 'revenue' => 300000, 'items' => []]);

        $res = $this->actingAsPerson($employee)
            ->get('/finance-list/export.csv?month=' . Carbon::parse($date)->format('Y-m'));

        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString($project->id, $csv);
        $this->assertStringContainsString('300000', $csv);
    }
}
