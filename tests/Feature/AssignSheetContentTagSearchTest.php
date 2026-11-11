<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentDifficulty;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** アサイン表の検索で、コンテンツの種別（新人ページの難易度表の種類・分類）でも探せる（2026-11-11 小沼さん）。 */
class AssignSheetContentTagSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_includes_difficulty_kind_and_category(): void
    {
        Content::create(['id' => 'CT-7', 'content_name' => 'ある会議室からの脱出', 'active' => true]);
        ContentDifficulty::create(['kind' => 'リアル', 'sheet_name' => '会議室脱出', 'category' => '謎解き脱出', 'difficulty' => 2, 'must' => true, 'content_id' => 'CT-7']);
        $d = Carbon::today()->startOfMonth()->addDays(5);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $d->toDateString(), 'status' => '未着手', 'content_ids' => ['CT-7']]);
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);

        $html = $this->actingAsPerson($me)->get('/assign-sheet?month='.$d->format('Y-m'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-search="[^"]*リアル\|謎解き脱出\|新人必修[^"]*"/u', $html, '必修のコンテンツは「新人」で探せる');
    }
}
