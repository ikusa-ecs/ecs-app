<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 前泊・後泊をカレンダーに出す（2026-11-02・FBフォーム 馬場さん）。
 *   ・スタッフ画面のカレンダー＝確定アサインの前の日・次の日に「🛏 前泊／後泊」の帯（画面のJSで描く）。
 *   ・社員のマイページ＝「📅 登録」「今後をまとめて登録」の横に「🛏 前泊／後泊」のGoogleカレンダー登録。
 * どちらも前泊と数える言葉は App\Support\Lodging から受け取る（画面に言葉を直書きしない）。
 */
class StayCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_portal_gets_stay_words_and_lodging(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->published()->create(['start_date' => $date, 'lodging' => '前後泊あり', 'stay_pre_meet_time' => '18:00']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $me->id, 'date' => $date, 'role' => 'FC', 'status' => '確定']);

        $res = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()
            ->assertSee('const ECS_PRE_STAY_WORDS = '.json_encode(\App\Support\Lodging::PRE_STAY_WORDS), false)
            ->assertSee('const ECS_POST_STAY_WORDS = '.json_encode(\App\Support\Lodging::POST_STAY_WORDS), false)
            ->assertSee('jc-stay', false);
        $row = collect($res->original->getData()['published'])->firstWhere('id', $p->id);
        $this->assertSame('前後泊あり', $row['lodging']);
        $this->assertSame('18:00', $row['stayPreMeet']);
    }

    public function test_mypage_gets_stay_words_and_pre_meet(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee']);
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['start_date' => $date, 'lodging' => '前泊有', 'stay_pre_meet_time' => '19:30']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $me->id, 'date' => $date, 'role' => 'D', 'status' => '確定']);

        $res = $this->actingAsPerson($me)->get('/mypage')->assertOk()
            ->assertSee('window.ECS_PRE_STAY_WORDS = '.json_encode(\App\Support\Lodging::PRE_STAY_WORDS), false)
            ->assertSee('function stayCalLinks', false);
        $row = collect($res->original->getData()['cases'])->firstWhere('id', $p->id);
        $this->assertNotNull($row);
        $this->assertSame('19:30', $row['stayPreMeet']);
    }
}
