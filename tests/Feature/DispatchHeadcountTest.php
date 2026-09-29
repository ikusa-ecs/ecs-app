<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ProjectDispatch;
use App\Support\AssignSlots;
use App\Support\DispatchRows;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 派遣は人数ぶん数える（2026-09-29 baba決定）。
 *
 * 声：「派遣を10名で登録しても、メンバーの人数に1としか数えられず、実際に足りない人数とずれる」。
 * 決めたこと：
 *   ・派遣10名＝10名。数えるのは **依頼中＋確定**（キャンセルは数えない）。
 *   ・全部の画面で同じ数え方（アサイン表・日別ボード・スタッフ画面など）。正本＝DispatchRows::liveCountsFor / liveCount。
 */
class DispatchHeadcountTest extends TestCase
{
    use RefreshDatabase;

    private function dispatch($p, int $count, string $status = '依頼中'): void
    {
        ProjectDispatch::create([
            'project_id' => $p->id, 'agency' => '派遣会社'.$count, 'count' => $count,
            'status' => $status, 'requested_on' => $p->start_date->format('Y-m-d'),
        ]);
    }

    private function manager()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
    }

    public function test_キャンセル以外を人数ぶん数える(): void
    {
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => Carbon::today()->addDays(5)->format('Y-m-d')]);
        $this->dispatch($p, 10, '依頼中');
        $this->dispatch($p, 3, '確定');
        $this->dispatch($p, 5, 'キャンセル');

        $this->assertSame([$p->id => 13], DispatchRows::liveCountsFor([$p->id]));
    }

    public function test_アサイン表のNOは派遣の人数ぶん進む(): void
    {
        $members = [['roleCode' => 'D'], ['roleCode' => 'OP']];
        $dispatches = [
            ['role' => 'OP', 'count' => 10, 'cancelled' => false],
            ['role' => 'OP', 'count' => 4, 'cancelled' => true],
        ];

        $lines = AssignSlots::rows($members, $dispatches, []);
        $dsp = array_values(array_filter($lines, fn ($l) => $l['kind'] === 'dispatch'));

        $this->assertSame(3, $dsp[0]['no'], 'Dと OP の次＝3番から');
        $this->assertSame(12, $dsp[0]['noEnd'], '10名ぶん＝12番まで');
        $this->assertNull($dsp[1]['no'], 'キャンセルはNOを使わない');
        $this->assertSame(12, AssignSlots::lastNo($lines));
    }

    public function test_アサイン表で運営人数が派遣で埋まれば足りている(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'required_count' => 12, 'scale' => '中型']);
        $staff = PersonFactory::new()->create(['office' => '東京']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $staff->id, 'role' => 'OP', 'status' => '確定', 'date' => $date]);
        $this->dispatch($p, 11);

        $res = $this->actingAsPerson($this->manager())->get('/assign-sheet?month='.Carbon::parse($date)->format('Y-m'))->assertOk();
        $card = collect($res->original->getData()['cards'])->firstWhere('id', $p->id);

        $this->assertSame(12, $card['filled'], '1名＋派遣11名＝12名');
        $this->assertSame(0, substr_count($res->getContent(), 'need-empty"'), '黄色の空き行は出ない');
    }

    public function test_日別ボードとスタッフ画面も派遣を数える(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $date, 'required_count' => 12]);
        $this->dispatch($p, 10);

        $board = collect($this->actingAsPerson($this->manager())->get('/assign')->assertOk()->viewData('boardCases'))
            ->firstWhere('id', $p->id);
        $this->assertSame(10, $board['filled']);

        // 画面のJSも同じ足し算（filledOf／confirmedOf に派遣を足す）。
        $html = $this->actingAsPerson($this->manager())->get('/assign')->getContent();
        $this->assertStringContainsString('function filledOf(c){ return c.assigned.length + dispatchLiveOf(c); }', $html);
        $this->assertStringContainsString("m.status === '確定').length + dispatchLiveOf(c); }", $html);
    }
}
