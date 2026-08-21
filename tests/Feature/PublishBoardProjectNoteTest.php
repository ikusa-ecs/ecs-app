<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 備考は1つ（2026-08-21 baba）。
 *
 * 以前は案件登録の備考（projects.note）と公開ボードの担当メモ（projects.publish_memo）に
 * 分かれていて、「案件登録で書いたのに公開ボードに出ない」と混乱した。
 * これからは note 1つ＝どちらの画面で書いても同じ備考になる。
 */
class PublishBoardProjectNoteTest extends TestCase
{
    use RefreshDatabase;

    /** 案件登録で書いた備考が、公開ボードの備考欄にそのまま出る。 */
    public function test_publish_board_shows_the_project_note(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        ProjectFactory::new()->create([
            'start_date' => Carbon::today()->addDays(4)->format('Y-m-d'),
            'office'     => '東京',
            'note'       => '駐車場は南側のみ利用可',
        ]);

        $html = $this->actingAsPerson($emp)->get('/assign-publish')->assertOk()->getContent();

        // 画面へは JSON（@json）で渡るため、日本語が \uXXXX に変換された形でも探す。
        $contains = fn (string $w) => str_contains($html, $w)
            || str_contains($html, trim(json_encode($w), '"'));

        $this->assertTrue($contains('駐車場は南側のみ利用可'), '案件登録の備考が公開ボードにも出る');
    }

    /** 公開ボードで直すと、案件登録の備考（note）が書き換わる。 */
    public function test_saving_from_the_board_updates_the_project_note(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $p = ProjectFactory::new()->create([
            'start_date' => Carbon::today()->addDays(4)->format('Y-m-d'),
            'office'     => '東京',
            'note'       => '古い備考',
        ]);

        $this->actingAsPerson($emp)
            ->postJson('/assign-publish/memo', ['id' => $p->id, 'memo' => '新しい備考'])
            ->assertOk();

        $this->assertSame('新しい備考', Project::find($p->id)->note);
    }
}
