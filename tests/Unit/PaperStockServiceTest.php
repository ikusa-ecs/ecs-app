<?php

namespace Tests\Unit;

use App\Models\ContentPaperStock;
use App\Support\PaperStockService;
use Database\Factories\ContentFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 謎解きの紙 在庫集計（PaperStockService）の単体テスト。テスト仕様書 UT-PPR-01。
 *
 * 集計ルール：
 *   ・必要枚数 = ceil(チーム数) × sheets_per_team
 *   ・開催日が今日以降＝「必要数(今後)」、過去＝「消費数(開催済み)」
 *   ・在庫 = 入庫 − 消費 / 過不足 = 在庫 − 必要数(今後)
 *   ・下書き／オンライン開催は集計対象外
 * DB が必要（RefreshDatabase）。
 */
class PaperStockServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 在庫＝入庫−消費、必要数(今後)、不足数の一連の計算が正しい。 */
    public function test_stock_equals_received_minus_consumed(): void
    {
        // 紙が必要なコンテンツ：1チーム2枚。
        $c = ContentFactory::new()->create([
            'id' => 'CT-P01',
            'content_name' => '謎解きA',
            'needs_paper' => true,
            'sheets_per_team' => 2,
        ]);

        // 開催済み（過去）：チーム5 → 5×2 = 10枚 消費。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P01'],
            'start_date' => now()->subDays(10)->format('Y-m-d'),
            'team_count' => 5,
            'status' => '完了',
            'format' => '対面',
        ]);

        // 今後（未来）：チーム3 → 3×2 = 6枚 必要。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P01'],
            'start_date' => now()->addDays(10)->format('Y-m-d'),
            'team_count' => 3,
            'status' => '確定',
            'format' => '対面',
        ]);

        // 入庫 15枚（手入力）。
        ContentPaperStock::create(['content_id' => 'CT-P01', 'received_count' => 15]);

        $result = (new PaperStockService())->compute();
        $row = collect($result['stock'])->firstWhere('id', 'CT-P01');

        $this->assertSame(6, $row['future'], '必要数(今後)');
        $this->assertSame(10, $row['past'], '消費数(開催済み)');
        $this->assertSame(15, $row['received'], '入庫数');
        $this->assertSame(5, $row['zaiko'], '在庫＝入庫15−消費10');
        // 過不足＝在庫5−必要6 = −1 → 不足1。
        $this->assertSame(1, $row['short'], '不足数');
    }

    /** チーム数が空ならお客様人数÷6(切り上げ)で推定される。 */
    public function test_teams_estimated_from_guest_count(): void
    {
        ContentFactory::new()->create([
            'id' => 'CT-P02',
            'needs_paper' => true,
            'sheets_per_team' => 1,
        ]);

        // team_count 未入力・guest_count 13 → ceil(13/6)=3チーム → 3×1 = 3枚（今後）。
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P02'],
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'team_count' => null,
            'guest_count' => 13,
            'status' => '確定',
            'format' => '対面',
        ]);

        $result = (new PaperStockService())->compute();
        $row = collect($result['stock'])->firstWhere('id', 'CT-P02');

        $this->assertSame(3, $row['future'], 'guest_count 13 → ceil(13/6)=3チーム×1枚');
    }

    /** 下書き案件とオンライン開催は集計対象外。 */
    public function test_draft_and_online_projects_are_excluded(): void
    {
        ContentFactory::new()->create([
            'id' => 'CT-P03',
            'needs_paper' => true,
            'sheets_per_team' => 1,
        ]);

        // 下書き（除外）
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P03'],
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'team_count' => 4,
            'status' => '下書き',
            'format' => '対面',
        ]);

        // オンライン（紙不要・除外）
        ProjectFactory::new()->create([
            'content_ids' => ['CT-P03'],
            'start_date' => now()->addDays(4)->format('Y-m-d'),
            'team_count' => 4,
            'status' => '確定',
            'format' => 'オンライン',
        ]);

        $result = (new PaperStockService())->compute();
        $row = collect($result['stock'])->firstWhere('id', 'CT-P03');

        // どちらも数えないので必要数は0。
        $this->assertSame(0, $row['future']);
        $this->assertSame(0, $row['past']);
    }

    /** needs_paper=false のコンテンツは在庫表に出ない（対象外）。 */
    public function test_non_paper_content_is_not_listed(): void
    {
        ContentFactory::new()->create([
            'id' => 'CT-NOPAPER',
            'needs_paper' => false,
        ]);

        $result = (new PaperStockService())->compute();

        $this->assertNull(
            collect($result['stock'])->firstWhere('id', 'CT-NOPAPER'),
            '紙不要コンテンツが在庫表に混ざっている'
        );
    }
}
