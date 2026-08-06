<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\FinanceReminderLog;
use App\Models\ProjectFinance;
use App\Support\FinanceReminderService;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 収支未入力リマインド（/finance-reminder）。
 *
 * ルール（2026-08-06 baba確定）：収支の入力はイベント終了後3営業日以内。
 * 締切を過ぎても未入力の案件を拾い、D（ディレクター）へチャットワークでタスクを付ける。
 *
 * ※ 実際の送信（チャットワークAPI）はここでは呼ばない。「誰を拾うか」を確かめる。
 */
class FinanceReminderTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FinanceReminderService
    {
        return app(FinanceReminderService::class);
    }

    /** 締切を過ぎて未入力の案件だけを拾う（入力済み・開催前・締切前は拾わない）。 */
    public function test_collects_only_overdue_and_unfilled_projects(): void
    {
        $today = Carbon::today();

        // ① 締切を過ぎて未入力 → 拾う
        $overdue = ProjectFactory::new()->create(['start_date' => $today->copy()->subDays(14)->format('Y-m-d')]);
        // ② 締切を過ぎているが入力済み → 拾わない
        $filled = ProjectFactory::new()->create(['start_date' => $today->copy()->subDays(14)->format('Y-m-d')]);
        ProjectFinance::create(['project_id' => $filled->id, 'revenue' => 100000, 'items' => []]);
        // ③ まだ開催前 → 拾わない
        ProjectFactory::new()->create(['start_date' => $today->copy()->addDays(3)->format('Y-m-d')]);
        // ④ 昨日開催＝締切前 → 拾わない
        ProjectFactory::new()->create(['start_date' => $today->copy()->subDay()->format('Y-m-d')]);
        // ⑤ 下書き → 拾わない
        ProjectFactory::new()->draft()->create(['start_date' => $today->copy()->subDays(14)->format('Y-m-d')]);
        // ⑥ 古すぎる（60日より前） → 拾わない
        ProjectFactory::new()->create(['start_date' => $today->copy()->subDays(120)->format('Y-m-d')]);

        $ids = collect($this->service()->collectCases())->pluck('id')->all();

        $this->assertSame([$overdue->id], $ids);
    }

    /** 経費だけ入っていても「入力済み」として扱う（売上ゼロの案件で催促し続けないため）。 */
    public function test_cost_only_counts_as_filled(): void
    {
        $project = ProjectFactory::new()->create(['start_date' => Carbon::today()->subDays(10)->format('Y-m-d')]);
        ProjectFinance::create([
            'project_id' => $project->id,
            'revenue' => null,
            'items' => ['staff_real' => ['qty' => 2]],
        ]);

        $this->assertSame([], collect($this->service()->collectCases())->pluck('id')->all());
    }

    /** 一度送った案件は「送信済み」になり、次からは送る対象に入らない。 */
    public function test_already_sent_projects_are_marked(): void
    {
        $project = ProjectFactory::new()->create(['start_date' => Carbon::today()->subDays(10)->format('Y-m-d')]);
        FinanceReminderLog::create(['dedup_key' => $project->id, 'project_id' => $project->id]);

        $cases = $this->service()->collectCases();

        $this->assertCount(1, $cases, '表には残す（状態が分かるように）');
        $this->assertTrue($cases[0]['alreadySent']);

        // 件数確認（dry）では、送信対象は0件になる。
        $result = $this->service()->run('dry');
        $this->assertSame(0, $result['hit']);
        $this->assertSame(1, $result['skipSent']);
    }

    /** DとクライアントがECSのデータから拾える（タスクを付ける相手が分かる）。 */
    public function test_case_includes_director_and_sales(): void
    {
        $date = Carbon::today()->subDays(10)->format('Y-m-d');
        $director = PersonFactory::new()->create(['name' => '田中 健一']);
        $project = ProjectFactory::new()->create([
            'start_date' => $date, 'client' => 'テスト商事', 'sales_owners' => ['営業 太郎'],
        ]);
        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $director->id,
            'date' => $date, 'role' => 'D', 'status' => '確定',
        ]);

        $case = $this->service()->collectCases()[0];

        $this->assertSame('田中 健一', $case['director']);
        $this->assertSame('営業 太郎', $case['sales']);
        $this->assertSame('テスト商事', $case['client']);
        $this->assertGreaterThan(0, $case['daysLate']);
    }

    /** 画面が開ける（社員以上）。スタッフは入れない。 */
    public function test_screen_opens_for_employee_only(): void
    {
        $employee = PersonFactory::new()->create();
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($employee)->get('/finance-reminder')->assertOk();
        $this->actingAsPerson($staff)->get('/finance-reminder')->assertRedirect('/staff-portal');
    }

    /** 件数確認（dry）は実際には送らない＝送信済み記録も増えない。 */
    public function test_dry_run_does_not_send_or_log(): void
    {
        $employee = PersonFactory::new()->create();
        ProjectFactory::new()->create(['start_date' => Carbon::today()->subDays(10)->format('Y-m-d')]);

        $this->actingAsPerson($employee)->post('/finance-reminder/send', ['mode' => 'dry'])
            ->assertRedirect('/finance-reminder');

        $this->assertSame(0, FinanceReminderLog::count());
    }
}
