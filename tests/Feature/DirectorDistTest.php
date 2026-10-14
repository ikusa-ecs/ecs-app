<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D決めの「🗓 日付で見る（イベプラ）」（2026-10-14 baba「新人ページの日付で見るがいい感じだからD決めでもイベプラだけで」）。
 * 表は画面のJS（renderDist）が作る＝カレンダーを描き直すたびに作り直す（担当を決めるとすぐ動く）。
 * ⚠ JSで作る表なのでHTMLに中身は出ない＝置き場所と、render() から呼んでいることを文字で見張る。
 */
class DirectorDistTest extends TestCase
{
    use RefreshDatabase;

    public function test_日付で見るの置き場所と呼び出し(): void
    {
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);
        $html = $this->actingAsPerson($me)->get('/assign-director')->assertOk()->getContent();

        $this->assertStringContainsString('id="distBox"', $html);
        $this->assertStringContainsString('function renderDist()', $html);
        $this->assertStringContainsString("renderAgg();\n    renderDist();", str_replace("\r\n", "\n", $html), 'カレンダーを描き直すたびに作り直す');
        $this->assertStringContainsString('EMP.filter(e => e.planner && sameOffice(e))', $html, 'イベプラだけ・見ている拠点だけ');
    }
}
