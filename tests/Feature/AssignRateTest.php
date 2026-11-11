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
 * 案件別アサイン（/project-assign）の「アサイン率」（2026-11-11 小沼さん）。
 * アサイン率＝その月に出られると示した日（エントリーした案件の日＋カレンダーで〇の日）のうち入れた日の割合（キャンセルは除く）。低い人ほどおすすめで上に来る。
 */
class AssignRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_assign_rate_comes_first(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $low = PersonFactory::new()->staff()->create(['name' => '入れてない人', 'office' => '東京']);
        $high = PersonFactory::new()->staff()->create(['name' => '入れてる人', 'office' => '東京']);

        $d = Carbon::today()->startOfMonth()->addDays(10);
        $mk = fn ($add) => ProjectFactory::new()->create(['office' => '東京', 'start_date' => $d->copy()->addDays($add)->toDateString(), 'status' => '未着手']);
        $target = $mk(0);
        $a = $mk(1);
        $b = $mk(2);
        $c = $mk(3);

        // 2人とも この案件＋他2件にエントリー（3件）。入れてる人は他2件に入っている＝2/3、入れてない人は0/3。
        foreach ([$low, $high] as $s) {
            foreach ([$target, $a, $b] as $p) {
                Application::create(['staff_id' => $s->id, 'project_id' => $p->id, 'intent' => '希望']);
            }
        }
        foreach ([$a, $b] as $p) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $high->id, 'role' => 'FC', 'status' => '確定', 'date' => $p->start_date]);
        }
        // キャンセルのアサインは「入れた」に数えない
        Application::create(['staff_id' => $low->id, 'project_id' => $c->id, 'intent' => '希望']);
        Assignment::create(['project_id' => $c->id, 'staff_id' => $low->id, 'role' => 'FC', 'status' => 'キャンセル', 'date' => $c->start_date]);

        // カレンダーで〇を5日・エントリー1件（入れた）＝1/5日（エントリーだけで数えると 1/1 で100%になっていた・小沼さん指摘）
        $cal = PersonFactory::new()->staff()->create(['name' => 'カレンダーの人', 'office' => '東京']);
        Application::create(['staff_id' => $cal->id, 'project_id' => $a->id, 'intent' => '希望']);
        Assignment::create(['project_id' => $a->id, 'staff_id' => $cal->id, 'role' => 'FC', 'status' => '確定', 'date' => $a->start_date]);
        foreach ([1, 5, 6, 7, 8] as $add) {
            \App\Models\ShiftPreference::create(['staff_id' => $cal->id, 'period' => $d->format('Y-m'), 'date' => $d->copy()->addDays($add)->toDateString(), 'availability' => '稼働可']);
        }

        $res = $this->actingAsPerson($emp)->get('/project-assign?project='.$target->id)->assertOk()
            ->assertSee('アサイン率');
        $staff = collect($res->original->getData()['staff'])->keyBy('name');

        $this->assertSame('0%（0/4日）', $staff['入れてない人']['assignRate']);
        $this->assertSame('67%（2/3日）', $staff['入れてる人']['assignRate']);
        $this->assertSame('20%（1/5日）', $staff['カレンダーの人']['assignRate']);
        $this->assertGreaterThan($staff['入れてる人']['score'], $staff['入れてない人']['score'], '低い人ほど点数が上');
        $this->assertContains('アサイン率が低い 0%（0/4日）', $staff['入れてない人']['reasons']);
    }
}
