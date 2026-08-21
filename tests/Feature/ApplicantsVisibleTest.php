<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー（応募）した人が、アサインの各画面に出ることを守るテスト（2026-08-21 baba指摘）。
 *
 * 起きていたこと：/entries と /pickup には出るのに、日別ボード（/assign）と
 * 案件別アサイン（/project-assign）には出なかった。
 *  ・日別ボード … サーバーは applicants を渡していたが、画面のJSが詰め替えで落としていた
 *  ・案件別アサイン … applications をそもそも読んでいなかった（稼働希望だけ見ていた）
 */
class ApplicantsVisibleTest extends TestCase
{
    use RefreshDatabase;

    /** 日別ボード：サーバーが応募者を渡している。 */
    public function test_day_board_passes_applicants(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $staff = PersonFactory::new()->staff()->create(['name' => '応募したスタッフ', 'office' => '東京']);
        $p = ProjectFactory::new()->create([
            'office'     => '東京',
            'start_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'status'     => '未着手',
        ]);
        Application::create(['staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望']);

        $cases = collect(
            $this->actingAsPerson($emp)->get('/assign')->assertOk()->original->getData()['boardCases']
        );

        $case = $cases->firstWhere('id', $p->id);
        $this->assertNotNull($case, '案件がボードに出ること');
        $this->assertSame(
            [$staff->id],
            collect($case['applicants'])->pluck('id')->all(),
            '応募者がボードのデータに入っていること'
        );
    }

    /** 日別ボード：画面のJSが applicants を詰め替えている（落とすと希望者欄が空になる）。 */
    public function test_day_board_view_keeps_applicants_in_its_mapping(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);

        $html = $this->actingAsPerson($emp)->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString(
            'applicants:(c.applicants||[])',
            $html,
            '画面の詰め替えから applicants が消えると、応募者が「希望者」欄に出なくなる'
        );
    }

    /** 案件別アサイン：エントリーした人に印が付く。 */
    public function test_project_assign_marks_entries(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $staff = PersonFactory::new()->staff()->create(['name' => '応募したスタッフ', 'office' => '東京']);
        $other = PersonFactory::new()->staff()->create(['name' => '応募していない人', 'office' => '東京']);
        $p = ProjectFactory::new()->create([
            'office'     => '東京',
            'start_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
        ]);
        Application::create([
            'staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望', 'note' => '入りたいです',
        ]);

        $list = collect(
            $this->actingAsPerson($emp)->get('/project-assign?project=' . urlencode($p->id))
                ->assertOk()->original->getData()['staff']
        )->keyBy('id');

        $this->assertTrue($list[$staff->id]['entry'], 'エントリーした人には印が付く');
        $this->assertSame('入りたいです', $list[$staff->id]['entryNote']);
        $this->assertFalse($list[$other->id]['entry']);
    }
}
