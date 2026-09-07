<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードのメンバー欄に同じ人が重ならないことを守るテスト
 * （2026-09-07 baba報告「案件カードの自動アサインを押すと重複する」）。
 *
 * 【原因】
 * アサインは「案件×人×日」で**1行**なので、2日以上の案件では同じ人の行が日数ぶんある。
 * 日別ボードのカードは**案件ごとに1枚**なのに、その行をそのまま並べていたため、
 * メンバー欄に同じ人が2回・3回出て、充足数（filled）も水増しされていた。
 * ⚠ 希望者（applicants）側は前から unique('staff_id') 済みで、メンバー側だけ抜けていた。
 *
 * ⚠ 残す1行の決め方＝**カードの日（案件の開催日）の行を優先 → 確定を優先**。
 */
class BoardMemberNoDuplicateTest extends TestCase
{
    use RefreshDatabase;

    /** 2日ある案件で同じ人に2行あっても、メンバー欄には1回だけ出る。 */
    public function test_multi_day_assignment_shows_once(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $staff = PersonFactory::new()->staff()->create(['name' => '重複太郎']);

        $day1 = Carbon::today()->addDays(10);
        $day2 = $day1->copy()->addDay();
        $p = ProjectFactory::new()->create(['start_date' => $day1->format('Y-m-d')]);

        foreach ([$day1, $day2] as $d) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $staff->id,
                'date' => $d->format('Y-m-d'), 'role' => 'OP', 'status' => '仮',
            ]);
        }

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $this->assertNotNull($card);
        $names = collect($card['assigned'])->pluck('name')->all();
        $this->assertSame(['重複太郎'], $names, 'メンバー欄に1回だけ出ること');
        $this->assertSame(1, $card['filled'], '充足数も1人ぶんで数えること');
    }

    /**
     * ⚠ 残す1行は「カードの日（開催日）」のもの。
     * 日によって役割が違うとき、開催日の役割が出ないと、カードの表示と当日が食い違う。
     */
    public function test_the_row_of_the_card_day_wins(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $staff = PersonFactory::new()->staff()->create(['name' => '役割花子']);

        $day1 = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->create(['start_date' => $day1->format('Y-m-d')]);

        // わざと「開催日でない日」を先に作る＝並べ替えが効いていないとこちらが残る。
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $day1->copy()->addDay()->format('Y-m-d'), 'role' => 'CK', 'status' => '仮',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $day1->format('Y-m-d'), 'role' => 'D', 'status' => '仮',
        ]);

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $row = collect($card['assigned'])->firstWhere('name', '役割花子');
        $this->assertSame('D', $row['roleCode'] ?? null, '開催日の役割が出ること');
        $this->assertCount(1, $card['assigned']);
    }

    /** 同じ日に「仮」と「確定」が並んでしまっていたら、確定を残す。 */
    public function test_confirmed_wins_over_tentative(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $staff = PersonFactory::new()->staff()->create(['name' => '確定次郎']);

        $day1 = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->create(['start_date' => $day1->format('Y-m-d')]);

        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $day1->copy()->addDay()->format('Y-m-d'), 'role' => 'OP', 'status' => '仮',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $day1->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
        ]);

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $this->assertCount(1, $card['assigned']);
        $this->assertSame('確定', $card['assigned'][0]['status']);
    }

    /** ふつうの1日案件・別の人は、これまでどおりそれぞれ出る（まとめすぎていないこと）。 */
    public function test_different_people_are_kept(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $a = PersonFactory::new()->staff()->create(['name' => 'Ａさん']);
        $b = PersonFactory::new()->staff()->create(['name' => 'Ｂさん']);

        $day = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d')]);
        foreach ([$a, $b] as $person) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $person->id,
                'date' => $day->format('Y-m-d'), 'role' => 'OP', 'status' => '仮',
            ]);
        }

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $this->assertCount(2, $card['assigned']);
        $this->assertSame(2, $card['filled']);
    }
}
