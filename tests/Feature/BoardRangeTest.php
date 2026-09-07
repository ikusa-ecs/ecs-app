<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボード（/assign）に並べる期間を守るテスト（2026-09-07 baba指示で 21日 → 42日）。
 *
 * 【なぜ要るか】
 * この日数はもともと **4か所** にバラバラに「21」と書いてあった
 * （案件を集める所／希望を読む所／件数を数える所／画面の絞り込み）。
 * 1つ直し忘れると「案件は出るのに、その日の希望者だけ出ない」という
 * 気づきにくい食い違いになる。ここで**同じ日数で動いていること**を確かめる。
 *
 * ⚠ 正本＝AssignBoardController::BOARD_DAYS。画面へは 'boardDays' で渡す。
 */
class BoardRangeTest extends TestCase
{
    use RefreshDatabase;

    /** 6週間（42日）先の案件までボードに出る。43日先は出ない。 */
    public function test_board_shows_six_weeks(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);

        $inside = ProjectFactory::new()->create([
            'start_date' => Carbon::today()->addDays(40)->format('Y-m-d'),
        ]);
        $edge = ProjectFactory::new()->create([
            'start_date' => Carbon::today()->addDays(42)->format('Y-m-d'),
        ]);
        $outside = ProjectFactory::new()->create([
            'start_date' => Carbon::today()->addDays(43)->format('Y-m-d'),
        ]);

        $data = $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData();
        $ids = collect($data['boardCases'])->pluck('id')->all();

        $this->assertSame(42, $data['boardDays'], '画面へ渡す日数も42であること');
        $this->assertContains($inside->id, $ids, '40日先は出る');
        $this->assertContains($edge->id, $ids, '42日先（ちょうど端）も出る');
        $this->assertNotContains($outside->id, $ids, '43日先は出ない');
    }

    /**
     * ⚠ 案件と希望が**同じ日数**で読まれていること。
     * 昔ここがずれていると「案件は出るのに、その日に終日〇の人が誰も出ない」になる。
     */
    public function test_wishes_are_read_for_the_same_range(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $staff = PersonFactory::new()->staff()->create(['office' => $me->office]);

        $day = Carbon::today()->addDays(40);
        ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d')]);
        ShiftPreference::create([
            'staff_id' => $staff->id,
            'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'),
            'availability' => '稼働可',
        ]);

        $data = $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData();

        $this->assertArrayHasKey(40, $data['boardAvail'], '40日先の希望が読まれていること');
        $this->assertContains(
            $staff->id,
            collect($data['boardAvail'][40])->pluck('id')->all(),
            '終日〇を出した人がその日の希望者に出ること'
        );
    }

    /** 件数バッジ（その人がボード期間に何件入っているか）も同じ期間で数える。 */
    public function test_month_count_uses_the_same_range(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $staff = PersonFactory::new()->staff()->create();

        $day = Carbon::today()->addDays(40);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d')]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $day->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
        ]);

        $data = $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData();

        $this->assertSame(1, (int) ($data['boardMonth'][$staff->name] ?? 0));
    }

    /** ⚠ 画面（Blade）に日数を書き戻していないこと。書くとサーバーとずれる。 */
    public function test_the_view_does_not_hardcode_the_range(): void
    {
        $blade = (string) file_get_contents(resource_path('views/assign.blade.php'));

        $this->assertStringContainsString('window.ECS_BOARD_DAYS', $blade);
        $this->assertStringNotContainsString('c.off <= 21', $blade,
            '画面に日数が直書きされています。サーバー（BOARD_DAYS）から受け取ってください。');
    }

    /**
     * お客様（参加者）の人数とチーム数がカードに届くこと（2026-09-07 baba要望）。
     *
     * ⚠ スタッフの運営人数（need）とは**別のもの**。取り違えると当日の規模を読み違える。
     * ⚠ 未入力は null で渡す。0名と未定は意味が違うので、0 に丸めないこと
     *   （丸めると画面で「0名」と出て、入れ忘れに気づけなくなる）。
     */
    public function test_card_carries_guest_and_team_counts(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);
        $day = Carbon::today()->addDays(10)->format('Y-m-d');

        $filled = ProjectFactory::new()->create([
            'start_date' => $day, 'guest_count' => 120, 'guest_count_type' => '募集',
            'team_count' => 8, 'team_tentative' => true,
        ]);
        $empty = ProjectFactory::new()->create([
            'start_date' => $day, 'guest_count' => null, 'team_count' => null,
        ]);

        $cards = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->keyBy('id');

        $this->assertSame(120, $cards[$filled->id]['guest']);
        $this->assertSame('募集', $cards[$filled->id]['guestType']);
        $this->assertSame(8, $cards[$filled->id]['teams']);
        $this->assertTrue($cards[$filled->id]['teamsTbd']);

        $this->assertNull($cards[$empty->id]['guest'], '未入力は0でなくnull');
        $this->assertNull($cards[$empty->id]['teams'], '未入力は0でなくnull');
    }

    /** ⚠ 画面で詰め替えを忘れるとカードに出ない（この画面でよくある事故）。 */
    public function test_the_view_passes_guest_and_team_through(): void
    {
        $blade = (string) file_get_contents(resource_path('views/assign.blade.php'));

        $this->assertStringContainsString('guest:c.guest', $blade);
        $this->assertStringContainsString('teams:c.teams', $blade);
        $this->assertStringContainsString('guestTeamHtml(c)', $blade);
    }
}
