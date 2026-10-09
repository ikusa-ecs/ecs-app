<?php

namespace Tests\Feature;

use App\Support\RecentWindow;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「3か月より前の案件は、ふだんの画面で読まない」（2026-10-09 baba決定）。
 * 過去のアサイン表（2023年〜・約2,500件）を取り込んでも画面が重くならないようにするため。
 * それより前は /past-projects で見る。範囲の正本＝App\Support\RecentWindow。
 *
 * 見張るのは：
 *   ① 案件一覧・公開ボード・ダッシュボードに古い案件が出ない（新しい案件・日付未定は出る）
 *   ② リピートの判定は古い案件も数える（一覧が3か月ぶんでも、昔からの常連にバッジが付く）
 *   ③ アサインダッシュボードの「募集中」に、終わった公開ずみの案件を数えない
 *   ④ 過去案件の画面には古い案件が出る
 */
class RecentWindowTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(?string $date, array $attrs = [])
    {
        return ProjectFactory::new()->create(array_merge([
            'office' => '東京', 'start_date' => $date, 'status' => '確定',
        ], $attrs));
    }

    public function test_古い案件はふだんの画面で読まない(): void
    {
        $old = $this->project(RecentWindow::from()->subDay()->format('Y-m-d'), ['client' => 'フルイ社']);
        $edge = $this->project(RecentWindow::from()->format('Y-m-d'), ['client' => 'ギリギリ社']);
        $tbd = $this->project(null, ['client' => 'ミテイ社']);
        $me = $this->admin();

        $ids = collect($this->actingAsPerson($me)->get('/projects')->assertOk()->viewData('cases'))->pluck('id');
        $this->assertFalse($ids->contains($old->id), '案件一覧に3か月より前の案件が出ています');
        $this->assertTrue($ids->contains($edge->id), 'ちょうど3か月前の案件は出す');
        $this->assertTrue($ids->contains($tbd->id), '日付未定の案件は出す');

        $ids = collect($this->actingAsPerson($me)->get('/dashboard')->assertOk()->viewData('cases'))->pluck('id');
        $this->assertFalse($ids->contains($old->id), 'ダッシュボードに3か月より前の案件が出ています');

        // ④ 過去案件の画面には出る。
        $ym = Carbon::parse($old->start_date)->format('Y-m');
        $this->actingAsPerson($me)->get('/past-projects?ym='.$ym)->assertOk()->assertSee('フルイ社');
    }

    public function test_リピートの判定は古い案件も数える(): void
    {
        $this->project('2024-05-01', ['client' => 'ジョウレン社']);
        $this->project(Carbon::today()->addDays(5)->format('Y-m-d'), ['client' => 'ジョウレン社']);

        $repeat = $this->actingAsPerson($this->admin())->get('/projects')->assertOk()->viewData('repeatClients');
        $this->assertArrayHasKey('ジョウレン社', $repeat);
    }

    public function test_募集中に終わった公開ずみの案件を数えない(): void
    {
        // 過去の取込は「公開ずみ・確定」で入る。
        $this->project('2025-03-01', ['staff_published' => true]);
        $this->project(Carbon::today()->subDays(3)->format('Y-m-d'), ['staff_published' => true]);
        $this->project(Carbon::today()->addDays(3)->format('Y-m-d'), ['staff_published' => true]);

        $this->actingAsPerson($this->admin())->get('/assign-dashboard')
            ->assertOk()->assertViewHas('recruitCount', 1);
    }
}
