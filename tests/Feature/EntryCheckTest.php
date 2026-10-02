<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Project;
use App\Support\EntryCheck;
use Carbon\Carbon;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * エントリーの点検（2026-10-02 baba）。取込でIDがずれて、エントリーが別の案件を指していないか。
 * ⚠ 見るだけ。データを変えないことも見張る。
 */
class EntryCheckTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $id, string $date, string $name, string $client = 'A社'): Project
    {
        return Project::create([
            'id' => $id, 'project_name' => $name, 'client' => $client, 'start_date' => $date,
            'office' => '東京', 'status' => '未着手', 'staff_published' => true,
        ]);
    }

    private function entry(string $staff, string $pid, string $at): void
    {
        Application::create(['staff_id' => $staff, 'project_id' => $pid, 'intent' => '希望', 'applied_at' => $at]);
    }

    public function test_エントリーのあとに中身が書き換わった案件を出す(): void
    {
        PersonFactory::new()->staff()->create(['id' => 'S-1', 'name' => '先に押した人']);
        PersonFactory::new()->staff()->create(['id' => 'S-2', 'name' => 'あとで押した人']);

        Carbon::setTestNow('2026-09-20 10:00');
        $p = $this->project('P-1', '2026-11-08', '運動会');
        $this->entry('S-1', 'P-1', '2026-09-20 10:00');

        Carbon::setTestNow('2026-09-30 09:00');
        $p->update(['start_date' => '2026-11-15', 'project_name' => '謎解き']);   // 取込で書き換わった
        $this->entry('S-2', 'P-1', '2026-09-30 12:00');                         // 書き換わったあとに押した人
        Carbon::setTestNow();

        $r = EntryCheck::rows(Carbon::parse('2026-09-01'));

        $this->assertSame(['S-1'], array_column($r['changed'], 'staff_id'), '押し直していない人だけ出す');
        $labels = array_column($r['changed'][0]['diffs'], 'label');
        $this->assertContains('開催日', $labels);
        $this->assertContains('案件名', $labels);

        // 期間より前の変更は出さない
        $this->assertSame([], EntryCheck::rows(Carbon::parse('2026-10-01'))['changed']);
    }

    public function test_同じ日にあとから別の番号でできた案件と_消えた案件を出す(): void
    {
        PersonFactory::new()->staff()->create(['id' => 'S-1', 'name' => '古い方の人']);
        PersonFactory::new()->staff()->create(['id' => 'S-2', 'name' => '両方押した人']);

        Carbon::setTestNow('2026-09-20 10:00');
        $this->project('P-OLD', '2026-11-08', '運動会');
        $this->entry('S-1', 'P-OLD', '2026-09-20 10:00');
        $this->entry('S-2', 'P-OLD', '2026-09-20 10:00');
        $this->entry('S-1', 'P-GONE', '2026-09-20 10:00');

        Carbon::setTestNow('2026-09-30 09:00');
        $this->project('P-NEW', '2026-11-08', '運動会');
        $this->project('P-OTHER', '2026-11-08', '別の会', 'B社');   // 別の案件は出さない
        $this->entry('S-2', 'P-NEW', '2026-09-30 12:00');
        Carbon::setTestNow();

        $r = EntryCheck::rows(Carbon::parse('2026-09-01'));

        $this->assertCount(1, $r['twins']);
        $this->assertSame('S-1', $r['twins'][0]['staff_id']);
        $this->assertSame('P-NEW', $r['twins'][0]['twin_id']);
        $this->assertSame(['P-GONE'], array_column($r['missing'], 'project_id'));
    }

    public function test_管理者だけが開けて_データは変えない(): void
    {
        $employee = PersonFactory::new()->create(['permission' => 'employee', 'office' => '東京', 'must_onboard' => false]);
        $manager = PersonFactory::new()->manager()->create(['office' => '東京', 'must_onboard' => false]);
        $this->project('P-1', '2026-11-08', '運動会');
        $this->entry('S-9', 'P-1', '2026-09-20 10:00');

        $this->actingAsPerson($employee)->get('/entry-check')->assertForbidden();
        $this->actingAsPerson($manager)->get('/entry-check?from=2026-09-01')
            ->assertOk()->assertSee('エントリーの点検');
        $this->assertSame(1, Application::count());
    }
}
