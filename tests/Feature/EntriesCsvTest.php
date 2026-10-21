<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー一覧のCSV（2026-10-21 baba要望）。1行＝案件 × エントリーした人。
 * ⚠ 出す案件・人は画面と同じ決まり（キャンセル案件は出さない／拠点で見ているときは自拠点のスタッフだけ・入っている人は出す）。
 */
class EntriesCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_エントリーした人が1行ずつ出る(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $tokyo = PersonFactory::new()->staff()->create(['name' => '東京の人', 'office' => '東京']);
        $nagoya = PersonFactory::new()->staff()->create(['name' => '名古屋の人', 'office' => '名古屋']);
        $day = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['start_date' => $day, 'office' => '東京', 'project_name' => '水合戦', 'client' => 'A社']);
        $gone = ProjectFactory::new()->create(['start_date' => $day, 'office' => '東京', 'project_name' => '中止の案件', 'is_cancelled' => true]);

        Application::create(['project_id' => $p->id, 'staff_id' => $tokyo->id, 'intent' => '希望', 'note' => 'よろしくお願いします']);
        Application::create(['project_id' => $p->id, 'staff_id' => $nagoya->id, 'intent' => '可']);
        Application::create(['project_id' => $gone->id, 'staff_id' => $tokyo->id, 'intent' => '希望']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $tokyo->id, 'role' => 'FC', 'status' => '仮', 'date' => $day]);

        $res = $this->actingAsPerson($me)->get('/entries/export.csv?office='.urlencode('東京'))->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF開催日,曜日,日程種別,案件ID", $csv, 'Excel用のBOMと見出し');
        $this->assertStringContainsString('東京の人', $csv);
        $this->assertStringContainsString('よろしくお願いします', $csv);
        $this->assertStringContainsString(',仮,', $csv, 'アサインの状態が出る');
        $this->assertStringNotContainsString('名古屋の人', $csv, '東京で見ているときは名古屋のスタッフは出さない（画面と同じ）');
        $this->assertStringNotContainsString('中止の案件', $csv, 'キャンセルの案件は出さない（画面と同じ）');

        // 画面にダウンロードの入口がある。
        $this->get('/entries?office='.urlencode('東京'))->assertOk()
            ->assertSee('/entries/export.csv?office=', false);
    }
}
