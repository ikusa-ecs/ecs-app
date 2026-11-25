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
 * 2026-11-25 FB No.31・32（桑江さん）。
 * No.31＝アサイン表・エントリー一覧（月ごと）で、本人メモがある人に 💬（カーソルを乗せると読める）。
 * No.32＝案件別アサイン（案件を選ぶ）を、表示する拠点で絞る（はじめは自分の拠点）。
 */
class FbEntryNoteOfficePickTest extends TestCase
{
    use RefreshDatabase;

    public function test_アサイン表に本人メモの印が出る(): void
    {
        $d = Carbon::today()->startOfMonth()->addDays(5);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $d->toDateString(), 'status' => '未着手']);
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $withNote = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        $noNote = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        Application::create(['staff_id' => $withNote->id, 'project_id' => $p->id, 'intent' => '希望', 'note' => '15時までなら入れます']);
        Application::create(['staff_id' => $noNote->id, 'project_id' => $p->id, 'intent' => '希望']);
        foreach ([$withNote, $noNote] as $s) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $s->id, 'date' => $d->toDateString(), 'role' => 'FC', 'status' => '仮']);
        }

        $html = $this->actingAsPerson($me)->get('/assign-sheet?month='.$d->format('Y-m'))->assertOk()
            ->assertSee('本人メモ：15時までなら入れます', false)
            ->getContent();
        $this->assertSame(1, substr_count($html, 'class="m-enote"'), 'メモが無い人には印を出さない');
    }

    public function test_エントリー一覧の月ごとに本人メモの印を出す部品がある(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($me)->get('/entries')->assertOk()->assertSee('m-note', false);
    }

    public function test_案件を選ぶ画面は自分の拠点の案件だけ並ぶ(): void
    {
        $d = Carbon::today()->addDays(10)->toDateString();
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $d, 'status' => '未着手', 'project_name' => 'TOKYO-CASE-X']);
        ProjectFactory::new()->create(['office' => '名古屋', 'start_date' => $d, 'status' => '未着手', 'project_name' => 'NAGOYA-CASE-X']);
        $nagoya = PersonFactory::new()->create(['permission' => 'manager', 'office' => '名古屋', 'must_onboard' => false]);
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京', 'must_onboard' => false]);

        // 一般の社員＝自分の拠点で固定
        $this->actingAsPerson($nagoya)->get('/assign-detail')->assertOk()
            ->assertSee('NAGOYA-CASE-X')->assertDontSee('TOKYO-CASE-X');
        // 管理者＝はじめは自分の拠点・切り替えると他の拠点・全拠点
        $this->actingAsPerson($admin)->get('/assign-detail')->assertOk()
            ->assertSee('TOKYO-CASE-X')->assertDontSee('NAGOYA-CASE-X');
        $this->actingAsPerson($admin)->get('/assign-detail?office='.urlencode('名古屋'))->assertOk()
            ->assertSee('NAGOYA-CASE-X')->assertDontSee('TOKYO-CASE-X');
        $this->actingAsPerson($admin)->get('/assign-detail?office=all')->assertOk()
            ->assertSee('NAGOYA-CASE-X')->assertSee('TOKYO-CASE-X');
    }
}
