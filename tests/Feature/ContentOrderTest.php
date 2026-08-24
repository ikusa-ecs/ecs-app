<?php

namespace Tests\Feature;

use App\Models\Content;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件登録のコンテンツ候補は「台帳の上からの順番」で出す（2026-08-24 baba要望）。
 *
 * これまではコンテンツ名の文字コード順だったため、マスタ管理の台帳で見えている
 * 並び（CT-001 から）と違っていて探しにくかった。
 * マスタ管理と同じ「並び順 → ID順」にそろえる。
 *
 * あわせて、台帳が空のときに昔のベタ書き12件を出すフォールバックをやめた
 * （台帳に無いコンテンツが選べてしまい、本物に見えるため）。
 */
class ContentOrderTest extends TestCase
{
    use RefreshDatabase;

    private function content(string $id, string $name, int $order = 0): Content
    {
        return Content::create([
            'id' => $id,
            'content_name' => $name,
            'active' => true,
            'sort_order' => $order,
        ]);
    }

    /** 台帳と同じ順（ID順）で候補が出る＝名前の文字コード順ではない。 */
    public function test_project_form_lists_contents_in_ledger_order(): void
    {
        // 名前の順に並べると「あ→わ」だが、台帳の順は CT-001（わ）→ CT-002（あ）。
        $this->content('CT-001', 'わくわく水合戦');
        $this->content('CT-002', 'あいうえお運動会');
        $this->content('CT-003', 'かきくけこ縁日');

        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/project-form')->assertOk()->getContent();

        $at = [];
        foreach (['わくわく水合戦', 'あいうえお運動会', 'かきくけこ縁日'] as $name) {
            $at[$name] = strpos($html, json_encode($name, JSON_UNESCAPED_SLASHES));
            $this->assertNotFalse($at[$name], $name.' が候補に無い');
        }

        $this->assertTrue($at['わくわく水合戦'] < $at['あいうえお運動会'], 'CT-001 が先に出ること');
        $this->assertTrue($at['あいうえお運動会'] < $at['かきくけこ縁日'], 'CT-002 が CT-003 より先に出ること');
    }

    /** マスタ管理で並び順を入れ替えたら、案件登録の候補もその順になる。 */
    public function test_sort_order_wins_over_id(): void
    {
        $this->content('CT-001', 'あとに出したい', 20);
        $this->content('CT-002', 'さきに出したい', 10);

        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/project-form')->assertOk()->getContent();

        $this->assertTrue(
            strpos($html, json_encode('さきに出したい', JSON_UNESCAPED_SLASHES))
            < strpos($html, json_encode('あとに出したい', JSON_UNESCAPED_SLASHES)),
            '並び順（sort_order）が先に効くこと'
        );
    }

    /** 台帳が空なら候補も空（昔のベタ書き12件を出さない）。 */
    public function test_no_hardcoded_contents_when_ledger_is_empty(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/project-form')->assertOk()->getContent();

        // 昔ベタ書きしていた並び（配列リテラル）が残っていないこと。
        $this->assertStringNotContainsString("'水合戦','運動会','縁日'", $html);
    }
}
