<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 公開ボードで「案件登録の備考」も読める（2026-08-21 baba指摘）。
 *
 * 案件登録の備考（projects.note）と、公開ボードの備考（projects.publish_memo）は別の項目。
 * 同じ「備考」という名前なので、案件登録で書いたのに公開ボードに出ないと混乱する。
 * 公開ボードでは案件登録の備考を「読むだけ」で出す（直すのは案件登録）。
 */
class PublishBoardProjectNoteTest extends TestCase
{
    use RefreshDatabase;

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

        $this->assertTrue($contains('駐車場は南側のみ利用可'), '案件登録の備考が公開ボードにも届く');
        $this->assertStringContainsString('projNote', $html);
    }
}
