<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードのカードに営業担当を出す（2026-09-18 baba要望
 * 「グループ作成の時に営業担当もグループ招待するから日別ボードにもどこかに見えるようにしてほしい」）。
 *
 * ⚠ この画面はサーバーから来たカードを**JSで作り直している**。
 *   作り直すところ（詰め替え）に書き忘れると、サーバーが渡していても画面には出ない。
 *   ＝この画面で何度もやっている事故なので、詰め替えも文字で見張る。
 */
class BoardSalesOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);
    }

    private function project(?array $salesOwners)
    {
        return ProjectFactory::new()->published()->create([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'sales_owners' => $salesOwners,
        ]);
    }

    private function card(string $projectId): ?array
    {
        return collect(
            $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $projectId);
    }

    /** 営業担当がカードに渡っている。 */
    public function test_the_card_carries_the_sales_owner(): void
    {
        $p = $this->project(['大霜 知弘']);

        $this->assertSame('大霜 知弘', $this->card($p->id)['sales'] ?? null);
    }

    /** 複数いるときは「・」でつなぐ（案件一覧・LINEの文章と同じ書き方）。 */
    public function test_multiple_sales_owners_are_joined(): void
    {
        $p = $this->project(['大霜 知弘', '桑江 一郎']);

        $this->assertSame('大霜 知弘・桑江 一郎', $this->card($p->id)['sales'] ?? null);
    }

    /** 未入力のときは空（カードに「営業：」の行を出さない）。 */
    public function test_empty_when_no_sales_owner(): void
    {
        $p = $this->project(null);

        $this->assertSame('', $this->card($p->id)['sales'] ?? null);
    }

    /**
     * ⚠ 詰め替えとカードの描画が書いてあること。
     * どちらかが抜けると、サーバーが渡していても画面には出ない。
     */
    public function test_the_screen_keeps_the_sales_owner(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('sales:c.sales', $html, '詰め替え（これが無いと画面に届かない）');
        $this->assertStringContainsString('escHtml(c.sales)', $html, 'カードに営業担当を描くところ');
    }
}
