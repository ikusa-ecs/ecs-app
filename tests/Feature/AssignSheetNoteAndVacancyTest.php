<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Support\RichNote;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表の2つ（2026-09-28 baba要望）。
 *   ① 案件の備考に「赤」「太字」を付けられる（正本＝App\Support\RichNote／partials/rich_note）
 *   ② 運営人数が入っている案件で、その人数までの行に名前が無いと、名前の欄が黄色になる
 */
class AssignSheetNoteAndVacancyTest extends TestCase
{
    use RefreshDatabase;

    private function sheet(string $date)
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);

        return $this->actingAsPerson($me)->get('/assign-sheet?month='.Carbon::parse($date)->format('Y-m'));
    }

    public function test_赤と太字の印が色になる(): void
    {
        $this->assertSame(
            '集合は<span class="rn-red">北口</span><br><b>雨天中止</b>',
            RichNote::html("集合は[赤]北口[/赤]\n**雨天中止**")
        );
        $this->assertSame("集合は北口\n雨天中止", RichNote::plain("集合は[赤]北口[/赤]\n**雨天中止**"));
    }

    public function test_備考にHTMLを書かれても画面は壊れない(): void
    {
        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', RichNote::html('<script>x</script>'));
        // 印の中身も無害化される。
        $this->assertSame('<b>&lt;i&gt;</b>', RichNote::html('**<i>**'));
    }

    public function test_アサイン表の備考が赤太字で出て編集ボタンもある(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'note' => '集合は[赤]北口[/赤]']);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringContainsString('集合は<span class="rn-red">北口</span>', $html);
        $this->assertStringContainsString("ecsRichWrap(this,'red')", $html);
        $this->assertStringContainsString('window.ecsRichNote', $html);
    }

    public function test_運営人数までの空き行だけ黄色(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'required_count' => 4]);
        $staff = PersonFactory::new()->create(['office' => '東京']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $staff->id, 'role' => 'OP', 'status' => '確定', 'date' => $date]);

        $html = $this->sheet($date)->assertOk()->getContent();

        // 4名のうち1名入っている＝残り3行が黄色。5行目から先は黄色にしない。
        $this->assertSame(3, substr_count($html, 'need-empty"'));
    }

    /** ＋社員に他拠点の社員も出る（新入社員が東京で研修に入る・2026-09-28 baba要望）。東京が上。 */
    public function test_他拠点の社員も選べる(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);
        PersonFactory::new()->create(['role' => 'employee', 'office' => '大阪', 'name' => '大阪の新人', 'active' => true]);
        PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '東京の社員', 'active' => true]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringContainsString('大阪の新人', $html);
        $tokyo = strpos($html, '<optgroup label="東京">');
        $osaka = strpos($html, '<optgroup label="大阪">');
        $this->assertNotFalse($tokyo);
        $this->assertNotFalse($osaka);
        $this->assertLessThan($osaka, $tokyo, 'いま見ている拠点（東京）が上');
    }

    public function test_運営人数が空なら黄色にしない(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'required_count' => null]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'need-empty"'));
    }
}
