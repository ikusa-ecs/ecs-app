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
}
