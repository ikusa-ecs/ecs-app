<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件一覧の「募集」の出し方（2026-08-21 baba指摘）。
 *
 * 以前は登録時の「募集する」だけを見ていたため、スタッフ公開ボードで公開していない案件まで
 * 「募集中」と出ていた。実際にはスタッフ画面に出ておらず応募も来ないので、
 * 公開しているか（staff_published）を画面に渡し、未公開は「未公開」と出す。
 */
class ProjectListRecruitStateTest extends TestCase
{
    use RefreshDatabase;

    private function open()
    {
        return $this->actingAsPerson(
            PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京'])
        )->get('/projects');
    }

    /** 公開していない案件は published=false として画面に渡る。 */
    public function test_unpublished_project_is_marked_as_not_published(): void
    {
        ProjectFactory::new()->create([
            'project_name'    => '未公開の案件',
            'start_date'      => '2026-09-10',
            'office'          => '東京',
            'is_recruiting'   => true,
            'staff_published' => false,
        ]);

        $this->open()->assertOk()->assertSee('"published":false', false);
    }

    /** 公開した案件は published=true になる。 */
    public function test_published_project_is_marked_as_published(): void
    {
        ProjectFactory::new()->create([
            'project_name'    => '公開ずみの案件',
            'start_date'      => '2026-09-11',
            'office'          => '東京',
            'is_recruiting'   => true,
            'staff_published' => true,
        ]);

        $this->open()->assertOk()->assertSee('"published":true', false);
    }
}
