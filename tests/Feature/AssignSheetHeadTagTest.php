<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表の日付の下（実施形態の横）に「巻き取り」「ヨミ」の札を出す（2026-11-12 小沼さん）。
 * 「追加」の札は前からある。ヨミは「確定」以外のときだけ出す。
 */
class AssignSheetHeadTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_巻き取りとヨミの札が出る・確定のヨミは出ない(): void
    {
        $date = Carbon::today()->addDays(10)->format('Y-m-d');
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $a = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'yomi' => 'Bヨミ', 'category' => '追加案件']);
        ProjectShare::create(['project_id' => $a->id, 'office' => '大阪', 'kind' => '巻き取り']);
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'yomi' => '確定']);

        $html = $this->actingAsPerson($me)
            ->get('/assign-sheet?month='.Carbon::parse($date)->format('Y-m'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<span class="htag takeover" title="他拠点との巻き取り">大阪が巻き取り</span>', $html);
        $this->assertStringContainsString('<span class="htag yomi" title="ヨミ（受注の見込み）">Bヨミ</span>', $html);
        $this->assertStringContainsString('<span class="htag extra">追加</span>', $html);
        $this->assertSame(1, substr_count($html, 'class="htag yomi"'), '確定のヨミまで札になっている');
    }
}
